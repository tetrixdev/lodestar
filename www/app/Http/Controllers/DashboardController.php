<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsOnboarding;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\WorkSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use BuildsOnboarding;

    /** A cross-project home: what needs the human, what AI is doing, what's due. */
    public function index(Request $request): View
    {
        $user = $request->user();
        // Owned + team projects (the central access rule), so a teammate's shared
        // project surfaces on the dashboard like it does everywhere else.
        $projectIds = Project::accessibleBy($user)->pluck('id');

        // Nothing to gather for a user with no projects.
        if ($projectIds->isEmpty()) {
            return view('dashboard', [
                'onboarding' => $this->onboarding($user),
                'reviewsToDo' => collect(),
                'awaitingReview' => collect(),
                'plansToApprove' => collect(),
                'backlog' => collect(),
                'aiWorking' => collect(),
                'dueSoon' => collect(),
                'sessions' => collect(),
            ]);
        }

        $taskBase = fn () => Task::query()
            ->whereIn('project_id', $projectIds)
            ->with('project:id,name');

        // ── Your inbox: bucketed by the ACTION each one needs from you ──────────

        // Reviews waiting — open reviews (the review is the unit you act on at the
        // human_review gate; tasks are loaded only to dedupe the safety net below).
        $reviewsToDo = Review::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', ['draft', 'in_review'])
            ->with('project:id,name', 'assignee:id,name', 'tasks:id,title')
            ->latest()
            ->get();

        // Safety net: a human_review card should be represented by its open review
        // above — but if one has none (e.g. set by hand), surface it so nothing that
        // needs a human can hide.
        $coveredByOpenReview = $reviewsToDo->flatMap->tasks->pluck('id')->unique();
        $awaitingReview = $taskBase()
            ->where('status', Task::STATUS_HUMAN_REVIEW)
            ->whereNotIn('id', $coveredByOpenReview)
            ->latest('status_changed_at')
            ->get();

        // Approve plans — cards waiting at the plan_review gate.
        $plansToApprove = $taskBase()
            ->where('status', Task::STATUS_PLAN_REVIEW)
            ->latest('status_changed_at')
            ->get();

        // Backlog — raw ideas that need you to plan, queue, or drop them.
        $backlog = $taskBase()
            ->where('status', Task::STATUS_NEW)
            ->latest('status_changed_at')
            ->get();

        // AI working now — the *-ing states.
        $aiWorking = $taskBase()
            ->whereIn('status', Task::workingStatuses())
            ->latest('status_changed_at')
            ->get();

        // Overdue / due soon — due today-or-past or within 7 days, still live.
        $dueSoon = $taskBase()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
            ->whereNotIn('status', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->orderBy('due_date')
            ->get();

        // Recent work sessions across projects.
        $sessions = WorkSession::query()
            ->whereIn('project_id', $projectIds)
            ->with('project:id,name', 'task:id,title')
            ->latest('occurred_on')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('dashboard', [
            'onboarding' => $this->onboarding($user),
            'reviewsToDo' => $reviewsToDo,
            'awaitingReview' => $awaitingReview,
            'plansToApprove' => $plansToApprove,
            'backlog' => $backlog,
            'aiWorking' => $aiWorking,
            'dueSoon' => $dueSoon,
            'sessions' => $sessions,
        ]);
    }
}
