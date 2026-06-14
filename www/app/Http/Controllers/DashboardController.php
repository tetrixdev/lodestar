<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsOnboarding;
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
        $projectIds = $user->projects()->pluck('id');

        // Nothing to gather for a user with no projects.
        if ($projectIds->isEmpty()) {
            return view('dashboard', [
                'onboarding' => $this->onboarding($user),
                'needsYou' => collect(),
                'reviews' => collect(),
                'aiWorking' => collect(),
                'dueSoon' => collect(),
                'sessions' => collect(),
            ]);
        }

        $taskBase = fn () => Task::query()
            ->whereIn('project_id', $projectIds)
            ->with('project:id,name');

        // Needs you — cards parked on a human gate.
        $needsYou = $taskBase()
            ->whereIn('status', [Task::STATUS_NEW, Task::STATUS_PLAN_REVIEW, Task::STATUS_HUMAN_REVIEW])
            ->latest('status_changed_at')
            ->get();

        // Reviews to do — open reviews in the user's projects.
        $reviews = Review::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', ['draft', 'in_review'])
            ->with('project:id,name', 'assignee:id,name')
            ->latest()
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
            'needsYou' => $needsYou,
            'reviews' => $reviews,
            'aiWorking' => $aiWorking,
            'dueSoon' => $dueSoon,
            'sessions' => $sessions,
        ]);
    }
}
