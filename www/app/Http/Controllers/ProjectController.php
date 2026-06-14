<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /** The current user's projects, each with progress + due-date summaries. */
    public function index(Request $request): View
    {
        $projects = $request->user()->projects()->latest()->withCount('tasks')->get();

        // Per-project rollups for the list cards: live task count, done count,
        // per-phase counts (live only), overdue count, and the next upcoming due.
        $summaries = [];
        foreach ($projects as $project) {
            $tasks = $project->tasks()->get();

            $live = $tasks->whereNotIn('status', [Task::STATUS_CANCELLED]);
            $done = $live->where('status', Task::STATUS_DONE)->count();
            $liveCount = $live->count();

            $phaseCounts = [];
            foreach (Task::PHASES as $key => $phase) {
                $phaseCounts[$key] = $live->whereIn('status', $phase['statuses'])->count();
            }

            $overdue = $live->filter(fn (Task $t) => $t->isOverdue())->count();

            $nextDue = $live
                ->filter(fn (Task $t) => $t->due_date !== null && ! in_array($t->status, [Task::STATUS_DONE], true))
                ->sortBy('due_date')
                ->first()?->due_date;

            $summaries[$project->id] = [
                'live' => $liveCount,
                'done' => $done,
                'phaseCounts' => $phaseCounts,
                'overdue' => $overdue,
                'nextDue' => $nextDue,
            ];
        }

        return view('projects.index', [
            'projects' => $projects,
            'summaries' => $summaries,
            'phases' => Task::PHASES,
        ]);
    }

    /** Create a project for the current user. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'primary_goal' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = $request->user()->projects()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'primary_goal' => $data['primary_goal'] ?? null,
        ]);

        return redirect()->route('projects.show', $project);
    }

    /** The project board — live tasks grouped by lifecycle status. */
    public function show(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        // Live (non-archived) cards, keyed by their precise status so the view
        // can place each into its phase column. Within a status, urgent-first
        // (by priority rank) then by position.
        $byStatus = $project->tasks()
            ->with('reviews:id')
            ->whereIn('status', Task::STATUSES)
            ->orderBy('position')
            ->get()
            ->sortBy([
                fn (Task $a, Task $b) => $b->priorityRank() <=> $a->priorityRank(),
                fn (Task $a, Task $b) => $a->position <=> $b->position,
            ])
            ->groupBy('status');

        $archived = $project->tasks()
            ->with('reviews:id')
            ->where('status', Task::STATUS_CANCELLED)
            ->latest('updated_at')
            ->get();

        // Distinct categories across live + archived cards, for the filter dropdown.
        $categories = $project->tasks()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('projects.show', [
            'project' => $project,
            'phases' => Task::PHASES,
            'byStatus' => $byStatus,
            'archived' => $archived,
            'categories' => $categories,
        ]);
    }

    /**
     * A dependency-free Gantt/timeline of the project's live tasks. Each task
     * becomes a bar from its start_date (falling back to status_changed_at /
     * created_at) to its due_date (falling back to start + 3 days). Bars are
     * CSS-positioned: left% / width% are computed here against the project's
     * overall date range, so the view needs no JS chart library.
     */
    public function gantt(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $tasks = $project->tasks()
            ->whereIn('status', Task::STATUSES)
            ->orderBy('position')
            ->get();

        // Resolve each task's [start, end] window first; we need them to find
        // the project-wide range before we can compute left%/width%.
        $resolved = $tasks->map(function (Task $task) {
            $start = $task->start_date
                ?? $task->status_changed_at
                ?? $task->created_at
                ?? now();
            $start = Carbon::parse($start)->startOfDay();

            $end = $task->due_date
                ? Carbon::parse($task->due_date)->startOfDay()
                : (clone $start)->addDays(3);

            // Guard against an end before start (e.g. a due date earlier than created).
            if ($end->lessThan($start)) {
                $end = (clone $start)->addDays(3);
            }

            return ['task' => $task, 'start' => $start, 'end' => $end];
        });

        $today = now()->startOfDay();

        // Project-wide range — include "today" so the marker always sits on the chart.
        $min = $resolved->min('start') ?? $today;
        $max = $resolved->max('end') ?? $today->copy()->addDays(7);
        $rangeStart = Carbon::parse($min)->min($today)->startOfDay();
        $rangeEnd = Carbon::parse($max)->max($today)->startOfDay();

        // Pad a little so first/last bars aren't flush to the edges.
        $rangeStart = $rangeStart->copy()->subDays(1);
        $rangeEnd = $rangeEnd->copy()->addDays(1);

        $totalDays = max(1, $rangeStart->diffInDays($rangeEnd));

        $pct = function (Carbon $date) use ($rangeStart, $totalDays): float {
            $offset = $rangeStart->diffInDays($date, false);

            return max(0, min(100, ($offset / $totalDays) * 100));
        };

        // Build rows grouped by phase.
        $rows = $resolved->map(function (array $r) use ($pct) {
            $left = $pct($r['start']);
            $right = $pct($r['end']);

            return [
                'task' => $r['task'],
                'start' => $r['start'],
                'end' => $r['end'],
                'left' => $left,
                'width' => max(1.5, $right - $left),
            ];
        });

        $byPhase = [];
        foreach (Task::PHASES as $key => $phase) {
            $byPhase[$key] = $rows->filter(
                fn (array $row) => in_array($row['task']->status, $phase['statuses'], true)
            )->values();
        }

        $todayPct = $pct($today);

        return view('projects.gantt', [
            'project' => $project,
            'phases' => Task::PHASES,
            'byPhase' => $byPhase,
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'todayPct' => $todayPct,
            'hasTasks' => $rows->isNotEmpty(),
        ]);
    }
}
