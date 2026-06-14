<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkSessionController extends Controller
{
    /** A project's work sessions, latest first. */
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $sessions = $project->workSessions()
            ->with('task:id,title')
            ->latest('occurred_on')
            ->latest('id')
            ->get();

        return view('sessions.index', ['project' => $project, 'sessions' => $sessions]);
    }

    /** The "log a session" form. */
    public function create(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $tasks = $project->tasks()->orderBy('title')->get(['id', 'title']);

        return view('sessions.create', ['project' => $project, 'tasks' => $tasks]);
    }

    /** Persist a new work session for the project. */
    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string'],
            'occurred_on' => ['nullable', 'date'],
            'task_id' => [
                'nullable',
                Rule::exists('tasks', 'id')->where('project_id', $project->id),
            ],
        ]);

        $session = $project->workSessions()->create([
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($project, $data['title']),
            'body' => $data['body'] ?? null,
            'occurred_on' => $data['occurred_on'] ?? null,
            'task_id' => $data['task_id'] ?? null,
        ]);

        return redirect()->route('work-sessions.show', $session);
    }

    /** A single work session. */
    public function show(Request $request, WorkSession $workSession): View
    {
        abort_unless($workSession->project->user_id === $request->user()->id, 403);

        $workSession->load('project', 'task:id,title');

        return view('sessions.show', ['session' => $workSession]);
    }

    /** A url-safe slug from the title, made unique within the project. */
    private function uniqueSlug(Project $project, string $title): string
    {
        $base = Str::slug($title) ?: 'session';
        $slug = $base;
        $n = 1;

        while ($project->workSessions()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
