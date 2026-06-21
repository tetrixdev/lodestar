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
    /** Project settings — name/goal, team assignment, and project approvers. */
    public function settings(Request $request, Project $project): View
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $project->load(['team', 'members']);

        // People who could be a project approver: the team's members (minus the
        // owner, who always approves). Personal projects have no candidate pool.
        $candidates = $project->team
            ? $project->team->members()->where('users.id', '!=', $project->user_id)->orderBy('name')->get()
            : collect();

        return view('projects.settings', [
            'project' => $project,
            'teams' => $request->user()->teams()->orderBy('name')->get(),
            'isOwner' => $project->user_id === $request->user()->id,
            'candidates' => $candidates,
        ]);
    }

    /** Edit name/description/goal; only the owner may reassign the team. */
    public function update(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'primary_goal' => ['nullable', 'string', 'max:2000'],
            'team_id' => ['nullable', 'integer'],
        ]);

        $update = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'primary_goal' => $data['primary_goal'] ?? null,
        ];

        // Only the owner can move a project between teams (or to personal). The
        // chosen team must be one the owner belongs to.
        if (array_key_exists('team_id', $data) && $project->user_id === $request->user()->id) {
            $teamId = $data['team_id'] ?: null;
            if ($teamId !== null && ! $request->user()->isInTeam((int) $teamId)) {
                return back()->withErrors(['team_id' => 'Pick a team you belong to.']);
            }
            $update['team_id'] = $teamId;
        }

        $project->update($update);

        return redirect()->route('projects.settings', $project)->with('status', 'Project updated.');
    }

    /** Add a project approver — must be a team member (owner only). */
    public function addApprover(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'can_approve_prompts' => ['nullable', 'boolean'],
        ]);

        // Eligible only if they belong to the project's team.
        $eligible = $project->team
            && $project->team->members()->whereKey($data['user_id'])->exists();
        if (! $eligible) {
            return back()->withErrors(['user_id' => 'That person is not on this project’s team.']);
        }

        $project->members()->syncWithoutDetaching([
            $data['user_id'] => ['can_approve_prompts' => $request->boolean('can_approve_prompts')],
        ]);

        return redirect()->route('projects.settings', $project)->with('status', 'Approver added.');
    }

    /** Remove a project approver (owner only). */
    public function removeApprover(Request $request, Project $project, int $user): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->members()->detach($user);

        return redirect()->route('projects.settings', $project)->with('status', 'Approver removed.');
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
        abort_unless($project->isAccessibleBy($request->user()), 403);

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
        abort_unless($project->isAccessibleBy($request->user()), 403);

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
