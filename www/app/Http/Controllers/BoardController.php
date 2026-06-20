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

        // A deliverable PROJECTS onto the board: while active it renders once per
        // column its tasks occupy (Plan + Build at the same time), each card filtered
        // to that column's tasks; task-level reviews sit under Build. Once it's in its
        // OWN review/ship state it's a single card in that column. Backlog = no tasks.
        // $deliverableCardsByPhase[phase] = [ ['deliverable'=>D, 'tasks'=>subset], ... ]
        $ownCardStates = [
            Deliverable::STATUS_AI_REVIEW,
            Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW,
            Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW,
            Deliverable::STATUS_APPROVED,
            Deliverable::STATUS_MERGING,
            Deliverable::STATUS_MERGED,
        ];
        // A child task's projection column: planning phases → plan; everything from
        // dev through its task-level reviews → build; done → nowhere.
        $taskProjection = function (string $status): ?string {
            if (in_array($status, [Task::STATUS_READY_FOR_PLANNING, Task::STATUS_PLANNING, Task::STATUS_PLAN_REVIEW], true)) {
                return 'plan';
            }
            if (in_array($status, [Task::STATUS_MERGED, Task::STATUS_CANCELLED], true)) {
                return null;
            }

            return 'build';
        };

        $deliverableCardsByPhase = [];
        foreach (array_keys(Task::PHASES) as $phaseKey) {
            $deliverableCardsByPhase[$phaseKey] = collect();
        }
        foreach ($deliverables as $d) {
            if (in_array($d->status, $ownCardStates, true)) {
                $deliverableCardsByPhase[$d->phaseColumn()]->push(['deliverable' => $d, 'tasks' => $d->tasks]);

                continue;
            }
            $incomplete = $d->tasks->whereNotIn('status', [Task::STATUS_MERGED, Task::STATUS_CANCELLED]);
            if ($incomplete->isEmpty()) {
                $deliverableCardsByPhase['backlog']->push(['deliverable' => $d, 'tasks' => collect()]);

                continue;
            }
            foreach ($incomplete->groupBy(fn (Task $t) => $taskProjection($t->status)) as $col => $subset) {
                if ($col !== null && isset($deliverableCardsByPhase[$col])) {
                    $deliverableCardsByPhase[$col]->push(['deliverable' => $d, 'tasks' => $subset->values()]);
                }
            }
        }

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
            'deliverableCardsByPhase' => $deliverableCardsByPhase,
            'tasksByPhase' => $tasksByPhase,
            'selectedId' => $selectedId,
            'needs' => $needs,
            'onboarding' => $this->onboarding($user),
        ]);
    }
}
