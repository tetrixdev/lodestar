<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /** Add a card to a project (lands at the bottom of the open column). */
    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:open,doing,done'],
        ]);

        $status = $data['status'] ?? 'open';

        $project->tasks()->create([
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'status' => $status,
            'position' => (int) $project->tasks()->where('status', $status)->max('position') + 1,
        ]);

        return back();
    }

    /** Move/edit a card (the ←/→ buttons and the cancel/restore flows use this). */
    public function update(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['nullable', 'in:open,doing,done,cancelled'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($data['status']) && $data['status'] !== $task->status) {
            $data['position'] = (int) $task->project->tasks()->where('status', $data['status'])->max('position') + 1;
        }

        $task->update(array_filter($data, fn ($v) => $v !== null));

        return back();
    }

    /**
     * Drag-and-drop persistence. Receives the destination column and the full,
     * ordered list of task ids now in that column. Updates the moved card's
     * status and rewrites position for every card in the destination column so
     * their relative order matches the client. Ownership is checked on every id.
     */
    public function move(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:open,doing,done'],
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        // Only act on ids that belong to this project (defends against spoofed ids).
        $ownedIds = $project->tasks()
            ->whereIn('id', $data['order'])
            ->pluck('id')
            ->all();
        $owned = array_flip($ownedIds);
        $ordered = array_values(array_filter($data['order'], fn ($id) => isset($owned[$id])));

        // The moved card must be part of the destination column it claims.
        abort_unless(in_array($task->id, $ordered, true), 422);

        DB::transaction(function () use ($project, $task, $data, $ordered): void {
            $task->update(['status' => $data['status']]);

            foreach ($ordered as $index => $id) {
                $project->tasks()->where('id', $id)->update([
                    'status' => $data['status'],
                    'position' => $index,
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
