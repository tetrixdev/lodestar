<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Deliverables: the optional Project → Deliverable → Task layer. Content CRUD only
 * (store / show / update) plus attaching child tasks. The deliverable's status is
 * DERIVED from its tasks + review outcomes (Deliverable::syncStatus) — there is no
 * manual advancement here. (Open questions now live as plan-review findings.)
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

    /** The deliverable detail page: concept/body/plan, child tasks (with their plan + functional reviews), lifecycle. */
    public function show(Request $request, Deliverable $deliverable): View
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $deliverable->load([
            'project',
            'tasks' => fn ($q) => $q->orderBy('sub_id'),
            // Full reviews so the page can list each task's plan + functional review.
            'tasks.reviews',
            'reviews' => fn ($q) => $q->orderBy('reviews.id'),
        ]);

        return view('deliverables.show', ['deliverable' => $deliverable]);
    }

    /** Freely-editable content fields (status is derived, never set here). */
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

    /** Attach a new child task to the deliverable (sub_id auto-assigned by the model). */
    public function addTask(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        // A manually-added child goes straight to the planning queue (the AI plans
        // it); the deliverable's status re-derives automatically.
        $entry = Task::STATUS_READY_FOR_PLANNING;
        $deliverable->tasks()->create([
            'project_id' => $deliverable->project_id,
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'status' => $entry,
            'position' => (int) $deliverable->project->tasks()->where('status', $entry)->max('position') + 1,
        ]);

        return back();
    }
}
