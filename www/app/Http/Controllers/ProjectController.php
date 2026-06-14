<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /** The current user's projects. */
    public function index(Request $request): View
    {
        $projects = $request->user()->projects()->latest()->withCount('tasks')->get();

        return view('projects.index', ['projects' => $projects]);
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

    /** The project board — tasks grouped by column. */
    public function show(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $byStatus = $project->tasks()
            ->whereIn('status', Task::COLUMNS)
            ->orderBy('position')
            ->get()
            ->groupBy('status');

        return view('projects.show', [
            'project' => $project,
            'columns' => Task::COLUMNS,
            'byStatus' => $byStatus,
        ]);
    }
}
