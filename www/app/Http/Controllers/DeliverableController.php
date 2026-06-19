<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deliverables: the optional Project → Deliverable → Task layer and its funnel
 * lifecycle. Mirrors TaskController's shape (store / show / update / move) plus
 * the deliverable-only pieces: answering the structured open questions (whose
 * all-answered state gates plan approval) and attaching child tasks.
 */
class DeliverableController extends Controller
{
    /** Create a deliverable from a raw concept (lands in `new`). */
    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'concept' => ['nullable', 'string'],
            'base_branch' => ['nullable', 'string', 'max:200'],
        ]);

        $deliverable = $project->deliverables()->create([
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'concept' => $data['concept'] ?? null,
            'base_branch' => $data['base_branch'] ?? null,
            'status' => Deliverable::STATUS_NEW,
            'position' => (int) $project->deliverables()->where('status', Deliverable::STATUS_NEW)->max('position') + 1,
        ]);

        return redirect()->route('deliverables.show', $deliverable);
    }

    /** The deliverable detail page: concept/body/plan, questions, child tasks, lifecycle. */
    public function show(Request $request, Deliverable $deliverable): View
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $deliverable->load([
            'project',
            'tasks' => fn ($q) => $q->orderBy('sub_id'),
            'tasks.reviews:id',
            'questions',
            'reviews' => fn ($q) => $q->orderBy('reviews.id'),
        ]);

        return view('deliverables.show', ['deliverable' => $deliverable]);
    }

    /** Freely-editable content fields (status moves go through move()). */
    public function update(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'base_branch' => ['nullable', 'string', 'max:200'],
            'concept' => ['nullable', 'string'],
            'concept_summary' => ['nullable', 'string', 'required_with:concept'],
            'body' => ['nullable', 'string'],
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'plan' => ['nullable', 'string'],
            'plan_summary' => ['nullable', 'string', 'required_with:plan'],
        ]);

        $fields = ['title', 'category', 'base_branch', 'concept', 'concept_summary', 'body', 'body_summary', 'plan', 'plan_summary'];
        $update = [];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $update[$field] = $data[$field] ?? null;
            }
        }

        if ($update !== []) {
            $deliverable->update($update);
        }

        return back();
    }

    /**
     * Move the deliverable along the funnel. LEGAL-ONLY (rejected with 422/validation
     * otherwise). Two extra gates: approving the plan (plan_review → building) requires
     * every open question answered; entering `building` stamps the integration branch
     * and base branch if not already set.
     */
    public function move(Request $request, Deliverable $deliverable): Response
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([...Deliverable::STATUSES, Deliverable::STATUS_CANCELLED])],
        ]);
        $target = $data['status'];

        if ($target === $deliverable->status) {
            return back();
        }

        if (! $deliverable->canTransitionTo($target)) {
            return $this->reject($request, "Illegal transition: {$deliverable->status} → {$target}.");
        }

        // Plan-approval gate: cannot leave plan_review for building with open questions.
        if ($deliverable->status === Deliverable::STATUS_PLAN_REVIEW
            && $target === Deliverable::STATUS_BUILDING
            && $deliverable->hasUnansweredQuestions()) {
            return $this->reject($request, 'Answer every open question before approving the plan.');
        }

        // Entering build: cut the integration branch identity if not set yet.
        if ($target === Deliverable::STATUS_BUILDING) {
            if (! $deliverable->branch) {
                $deliverable->branch = $deliverable->branchName();
            }
            if (! $deliverable->base_branch) {
                $deliverable->base_branch = 'main';
            }
        }

        $deliverable->status = $target;
        $deliverable->position = (int) $deliverable->project->deliverables()
            ->where('status', $target)->max('position') + 1;
        $deliverable->save();

        return back();
    }

    /** Record (or clear) the answer to an open question; stamps answered_at via the model. */
    public function answerQuestion(Request $request, Deliverable $deliverable, int $question): RedirectResponse
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $q = $deliverable->questions()->findOrFail($question);
        $data = $request->validate(['answer' => ['nullable', 'string']]);
        $q->update(['answer' => $data['answer'] ?? null]);

        return back();
    }

    /** Add an open question to the deliverable (planning agents do this via MCP; UI for testing). */
    public function addQuestion(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate(['question' => ['required', 'string']]);
        $deliverable->questions()->create([
            'question' => $data['question'],
            'position' => (int) $deliverable->questions()->max('position') + 1,
        ]);

        return back();
    }

    /** Attach a new child task to the deliverable (sub_id auto-assigned by the model). */
    public function addTask(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $deliverable->tasks()->create([
            'project_id' => $deliverable->project_id,
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'status' => Task::STATUS_NEW,
            'position' => (int) $deliverable->project->tasks()->where('status', Task::STATUS_NEW)->max('position') + 1,
        ]);

        return back();
    }

    /** Reject an illegal/blocked move: 422 JSON for programmatic callers, validation error for the HTML page. */
    private function reject(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422);
        }

        throw ValidationException::withMessages(['status' => [$message]]);
    }
}
