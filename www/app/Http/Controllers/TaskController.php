<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends Controller
{
    /** The per-task detail page: plan, description, reviews, deps, sessions, comments, activity. */
    public function show(Request $request, Task $task): View
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        $task->load([
            'project',
            'reviews',
            'workSessions' => fn ($q) => $q->latest(),
            'comments.user',
            'events',
            'dependencies',
            'dependents',
        ]);

        return view('tasks.show', ['task' => $task]);
    }

    /**
     * Leave an async note on a task. Human comments are stamped with the
     * author's name and logged to the activity timeline.
     */
    public function comment(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $task->comments()->create([
            'user_id' => $request->user()->id,
            'author' => $request->user()->name,
            'body' => $data['body'],
        ]);

        $task->logEvent('commented', $request->user()->name);

        return back();
    }

    /** Add a card to a project (lands at the bottom of its status). */
    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $status = $data['status'] ?? Task::STATUS_NEW;

        $project->tasks()->create([
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'status' => $status,
            'priority' => $data['priority'] ?? Task::PRIORITY_NORMAL,
            'due_date' => $data['due_date'] ?? null,
            'position' => (int) $project->tasks()->where('status', $status)->max('position') + 1,
        ]);

        return back();
    }

    /**
     * Move a card along the lifecycle (the per-card transition controls and the
     * archive/restore flows use this). Status changes are LEGAL-ONLY: a move to
     * a status that isn't in the card's allowed-transition set is rejected (422).
     * `status_changed_at` is stamped automatically by the model on status change.
     */
    public function update(Request $request, Task $task): Response
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'status' => ['nullable', Rule::in([...Task::STATUSES, Task::STATUS_CANCELLED])],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'branch' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string'],
            // A summary is mandatory whenever its long-form detail is set, so a
            // filled body/plan never ships without a scannable TL;DR. Editing a
            // legacy card therefore forces backfilling its summary.
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'plan' => ['nullable', 'string'],
            'plan_summary' => ['nullable', 'string', 'required_with:plan'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        if (isset($data['status']) && $data['status'] !== $task->status) {
            if (! $task->canTransitionTo($data['status'])) {
                $message = "Illegal transition: {$task->status} → {$data['status']}.";

                // This app only auto-renders JSON for api/* (see bootstrap/app.php),
                // so reject explicitly: 422 JSON for programmatic callers, a
                // redirect-with-errors for the HTML board (shown via $errors).
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $message,
                        'errors' => ['status' => [$message]],
                    ], 422);
                }

                throw ValidationException::withMessages(['status' => [$message]]);
            }

            $data['position'] = (int) $task->project->tasks()
                ->where('status', $data['status'])->max('position') + 1;
        }

        // Status/position are only applied when actually changing status; the
        // content fields below are freely editable (and nullable, so an empty
        // date/branch clears it — only fields the request actually sent are touched).
        $contentFields = ['title', 'category', 'priority', 'branch', 'body', 'body_summary', 'plan', 'plan_summary', 'start_date', 'due_date'];
        $update = [];

        if (isset($data['status']) && $data['status'] !== $task->status) {
            $update['status'] = $data['status'];
            $update['position'] = $data['position'];
        }

        foreach ($contentFields as $field) {
            if ($request->has($field)) {
                $update[$field] = $data[$field] ?? null;
            }
        }

        if ($update !== []) {
            $task->update($update);
        }

        return back();
    }

    /**
     * Manually release a stuck working (*-ing) card back to its queue (ready_*)
     * state, clearing the claim. This is the human escape hatch we chose instead
     * of an automatic lease/reaper: the happy flow is expected, and if an agent
     * crashed mid-task a person presses "release" and the loop re-picks it.
     */
    public function release(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        $queue = Task::queueStateFor($task->status);
        abort_unless($queue !== null, 422); // only *-ing tasks can be released

        $task->status = $queue;
        $task->claimed_by = null;
        $task->claimed_at = null;
        $task->position = (int) $task->project->tasks()
            ->where('status', $queue)->max('position') + 1;
        $task->save();

        return back();
    }

    /**
     * Intra-column reordering (drag within a single status). Receives the status
     * the cards sit in and the full, ordered list of task ids now in that status,
     * and rewrites `position` to match. This endpoint does NOT change status —
     * lifecycle moves go through update() so only legal transitions are allowed.
     * Ownership is checked on every id.
     */
    public function move(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        // Reordering only — the dragged card must already be in the target status.
        abort_unless($task->status === $data['status'], 422);

        // Only act on ids that belong to this project AND already sit in this
        // status (defends against spoofed ids and cross-status injection).
        $ownedIds = $project->tasks()
            ->where('status', $data['status'])
            ->whereIn('id', $data['order'])
            ->pluck('id')
            ->all();
        $owned = array_flip($ownedIds);
        $ordered = array_values(array_filter($data['order'], fn ($id) => isset($owned[$id])));

        abort_unless(in_array($task->id, $ordered, true), 422);

        DB::transaction(function () use ($project, $ordered): void {
            foreach ($ordered as $index => $id) {
                $project->tasks()->where('id', $id)->update(['position' => $index]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
