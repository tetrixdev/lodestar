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
 * to one. The board is deliverable-only: every task belongs to a deliverable, so
 * deliverables render as cards carrying their child-task one-liners. A compact
 * "needs you" strip carries the cross-project signals the old dashboard did.
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

        $deliverables = Deliverable::query()
            ->whereIn('project_id', $activeIds)
            ->whereIn('status', Deliverable::STATUSES) // exclude cancelled
            ->with(['project', 'tasks' => fn ($q) => $q->orderBy('sub_id')])
            ->orderBy('position')
            ->get();

        // A deliverable PROJECTS onto the board. Placement in BACKLOG / PLAN / BUILD
        // is derived from its TASKS, not its own status: while it still has open work
        // it renders once per column its tasks occupy (Plan + Build at the same time),
        // each card filtered to that column's tasks. Merged tasks stay VISIBLE (green)
        // under Build until EVERY non-cancelled task is merged. Once all tasks are
        // merged the deliverable LEAVES the task-derived columns and shows as a single
        // OWN card in its review/ship column, driven by its own status. Backlog = a
        // deliverable with zero non-cancelled tasks.
        // $deliverableCardsByPhase[phase] = [ ['deliverable'=>D, 'tasks'=>subset], ... ]
        //
        // Own-card states = the deliverable review-chain + ship statuses. A deliverable
        // only reaches these once all its tasks are merged (syncStatus derives
        // ready_for_ai_review when all child work is done); a corrective task pulls it
        // back to building, at which point task-derived placement takes over again.
        $ownCardStates = [
            Deliverable::STATUS_READY_FOR_AI_REVIEW,
            Deliverable::STATUS_AI_REVIEW,
            Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW,
            Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW,
            Deliverable::STATUS_APPROVED,
            Deliverable::STATUS_MERGING,
            Deliverable::STATUS_MERGED,
        ];
        // A child task's projection column: planning phases (incl. ready_for_planning)
        // → plan; everything from ready_for_dev through merged → build; cancelled →
        // nowhere. Merged maps to BUILD so it stays visible there (rendered green).
        $taskProjection = function (string $status): ?string {
            if (in_array($status, [Task::STATUS_READY_FOR_PLANNING, Task::STATUS_PLANNING, Task::STATUS_PLAN_REVIEW], true)) {
                return 'plan';
            }
            if ($status === Task::STATUS_CANCELLED) {
                return null;
            }

            return 'build';
        };

        $deliverableCardsByPhase = [];
        foreach (array_keys(Task::PHASES) as $phaseKey) {
            $deliverableCardsByPhase[$phaseKey] = collect();
        }
        foreach ($deliverables as $d) {
            // Own-card state (review/ship) → a single card in its own status column.
            if (in_array($d->status, $ownCardStates, true)) {
                $deliverableCardsByPhase[$d->phaseColumn()]->push(['deliverable' => $d, 'tasks' => $d->tasks]);

                continue;
            }

            // Task-derived placement: ignore cancelled tasks entirely.
            $active = $d->tasks->where('status', '!=', Task::STATUS_CANCELLED);

            // BACKLOG: a deliverable with zero non-cancelled tasks.
            if ($active->isEmpty()) {
                $deliverableCardsByPhase['backlog']->push(['deliverable' => $d, 'tasks' => collect()]);

                continue;
            }

            // Merged tasks remain visible (green) in BUILD until ALL are merged; once
            // every task is merged the deliverable has handed off to its own review/ship
            // card above (its status is no longer task-derived), so it won't reach here.
            foreach ($active->groupBy(fn (Task $t) => $taskProjection($t->status)) as $col => $subset) {
                if ($col !== null && isset($deliverableCardsByPhase[$col])) {
                    $deliverableCardsByPhase[$col]->push(['deliverable' => $d, 'tasks' => $subset->values()]);
                }
            }
        }

        // "Needs you" — cross-project signals, scoped to the active project set.
        // Child-task signals come from the deliverables' loaded tasks (the board is
        // deliverable-only — there are no loose tasks).
        $allTasks = $deliverables->flatMap->tasks;
        $needs = [
            'plans' => $allTasks->where('status', Task::STATUS_PLAN_REVIEW)->count()
                + $deliverables->where('status', Deliverable::STATUS_PLAN_REVIEW)->count(),
            'reviews' => $allTasks->where('status', Task::STATUS_HUMAN_REVIEW)->count()
                + $deliverables->whereIn('status', [
                    Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW,
                    Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW,
                ])->count(),
            'overdue' => $allTasks->filter(fn (Task $t) => $t->isOverdue())->count(),
        ];

        return view('board', [
            'projects' => $projects,
            'phases' => Task::PHASES,
            'deliverableCardsByPhase' => $deliverableCardsByPhase,
            'selectedId' => $selectedId,
            'needs' => $needs,
            'onboarding' => $this->onboarding($user),
        ]);
    }
}
