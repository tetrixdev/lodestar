<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsOnboarding;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The unified board (task #69): one cross-project board that is the authenticated
 * landing. Cards mix across every accessible project into the 5 shared phase
 * columns, each tagged with a project chip; an optional `?project=` filter narrows
 * to one. Deliverables render as cards (carrying their child-task one-liners);
 * standalone tasks (no deliverable) render as their own cards. A compact "needs
 * you" strip carries the cross-project signals the old dashboard did.
 */
class BoardController extends Controller
{
    use BuildsOnboarding;

    public function index(Request $request): View
    {
        $user = $request->user();

        $projects = Project::query()->accessibleBy($user)->orderBy('name')->get();
        $projectIds = $projects->pluck('id');

        // ?project=<id> narrows to one; anything else = all accessible projects.
        $selected = $request->query('project');
        $activeIds = ($selected !== null && $selected !== 'all' && $projectIds->contains((int) $selected))
            ? collect([(int) $selected])
            : $projectIds;
        $selectedId = $activeIds->count() === 1 && $selected !== 'all' && $selected !== null ? (int) $selected : null;

        // status => phase key, inverted from Task::PHASES, so a task lands in its column.
        $statusPhase = [];
        foreach (Task::PHASES as $key => $def) {
            foreach ($def['statuses'] as $status) {
                $statusPhase[$status] = $key;
            }
        }

        $deliverables = Deliverable::query()
            ->whereIn('project_id', $activeIds)
            ->whereIn('status', Deliverable::STATUSES) // exclude cancelled
            ->with(['project', 'tasks' => fn ($q) => $q->orderBy('sub_id')])
            ->orderBy('position')
            ->get();

        // Only STANDALONE tasks become their own cards; tasks under a deliverable
        // live inside the deliverable card.
        $tasks = Task::query()
            ->whereIn('project_id', $activeIds)
            ->whereNull('deliverable_id')
            ->whereIn('status', Task::STATUSES)
            ->with(['project', 'reviews:id'])
            ->orderBy('position')
            ->get()
            ->sortBy([
                fn (Task $a, Task $b) => $b->priorityRank() <=> $a->priorityRank(),
                fn (Task $a, Task $b) => $a->position <=> $b->position,
            ]);

        $deliverablesByPhase = $deliverables->groupBy(fn (Deliverable $d) => $d->phaseColumn());
        $tasksByPhase = $tasks->groupBy(fn (Task $t) => $statusPhase[$t->status] ?? 'backlog');

        // "Needs you" — cross-project signals, scoped to the active project set.
        $needs = [
            'plans' => $tasks->where('status', Task::STATUS_PLAN_REVIEW)->count()
                + $deliverables->where('status', Deliverable::STATUS_PLAN_REVIEW)->count(),
            'reviews' => $tasks->where('status', Task::STATUS_HUMAN_REVIEW)->count()
                + $deliverables->whereIn('status', [
                    Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW,
                    Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW,
                ])->count(),
            'overdue' => $tasks->filter(fn (Task $t) => $t->isOverdue())->count(),
        ];

        return view('board', [
            'projects' => $projects,
            'phases' => Task::PHASES,
            'deliverablesByPhase' => $deliverablesByPhase,
            'tasksByPhase' => $tasksByPhase,
            'selectedId' => $selectedId,
            'needs' => $needs,
            'onboarding' => $this->onboarding($user),
        ]);
    }
}
