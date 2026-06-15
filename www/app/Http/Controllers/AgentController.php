<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A fuller view of agent activity than the nav heartbeat: who is working what,
 * grouped by agent, across the projects the user can reach. Derived from the
 * cards currently in a working (*-ing) state — no persistent connection.
 */
class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $tasks = Task::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($request->user()))
            ->whereIn('status', Task::workingStatuses())
            ->with('project:id,name,slug')
            ->orderBy('claimed_at')
            ->get(['id', 'project_id', 'title', 'status', 'claimed_by', 'claimed_at']);

        // Group by the claiming agent (its claimed_by label).
        $agents = $tasks->groupBy(fn (Task $t) => $t->claimed_by ?: 'unclaimed')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'is_loop' => Str::startsWith((string) $name, 'loop'),
                'tasks' => $group,
                'projects' => $group->pluck('project.name')->unique()->values(),
            ])
            ->values();

        return view('agents.index', ['agents' => $agents, 'taskCount' => $tasks->count()]);
    }
}
