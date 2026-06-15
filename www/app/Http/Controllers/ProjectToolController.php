<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Per-project tools (programs to install + commands to provide) for the agent
 * workspace. Approver-managed (running project-supplied shell is a trusted-team
 * boundary). Fetched out-of-MCP via `manifest()` so long command scripts don't
 * eat the agent's context.
 */
class ProjectToolController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        return view('projects.tools', [
            'project' => $project,
            'tools' => $project->tools()->orderBy('kind')->orderBy('name')->get(),
            'canManage' => $project->canApprovePrompts($request->user()),
            'kinds' => ProjectTool::KINDS,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->canApprovePrompts($request->user()), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(ProjectTool::KINDS)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'check' => ['nullable', 'string'],
            'run' => ['required', 'string'],
        ]);

        $project->tools()->updateOrCreate(
            ['kind' => $data['kind'], 'name' => $data['name']],
            ['description' => $data['description'] ?? null, 'check' => $data['check'] ?? null, 'run' => $data['run']],
        );

        return back()->with('status', 'tool-saved');
    }

    public function destroy(Request $request, Project $project, ProjectTool $tool): RedirectResponse
    {
        abort_unless($project->canApprovePrompts($request->user()), 403);
        abort_unless($tool->project_id === $project->id, 404);

        $tool->delete();

        return back()->with('status', 'tool-removed');
    }

    /** Out-of-MCP manifest the agent reads during workspace setup. */
    public function manifest(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        return response()->json([
            'tools' => $project->tools()->orderBy('kind')->orderBy('name')->get()
                ->map(fn (ProjectTool $t) => [
                    'kind' => $t->kind,
                    'name' => $t->name,
                    'description' => $t->description,
                    'check' => $t->check,
                    'run' => $t->run,
                ])->all(),
        ]);
    }
}
