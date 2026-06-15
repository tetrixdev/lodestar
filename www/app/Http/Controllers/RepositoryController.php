<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use App\Services\GitHubAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Link / unlink repositories on a project (its "stack"). A repo is read through a
 * chosen GitHub connection; on link we confirm that connection's token can see
 * it (and capture its default branch). Many-to-many: the same repo can be linked
 * to several projects.
 */
class RepositoryController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        return view('projects.repositories', [
            'project' => $project,
            'repositories' => $project->repositories()->with('githubConnection')->get(),
            'connections' => $request->user()->githubConnections()->get(),
        ]);
    }

    public function store(Request $request, Project $project, GitHubAccount $github): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'github_connection_id' => ['required', 'integer'],
            'full_name' => ['required', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
        ]);

        // The connection must be the user's.
        $connection = $request->user()->githubConnections()->find($data['github_connection_id']);
        if (! $connection) {
            return back()->withErrors(['github_connection_id' => 'Pick one of your GitHub connections.']);
        }

        $branch = $github->defaultBranch($connection->token, $data['full_name']);
        if (! $branch) {
            return back()->withErrors(['full_name' => "That connection can't see {$data['full_name']} (wrong account or no access)."]);
        }

        // Reuse the repo row for this connection+name, then link it to the project.
        $repository = Repository::firstOrCreate(
            ['github_connection_id' => $connection->id, 'full_name' => $data['full_name']],
            ['default_branch' => $branch],
        );
        $project->repositories()->syncWithoutDetaching([$repository->id]);

        return redirect()->route('repositories.index', $project)->with('status', "Linked {$data['full_name']}.");
    }

    public function destroy(Request $request, Project $project, Repository $repository): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $project->repositories()->detach($repository->id);

        return redirect()->route('repositories.index', $project)->with('status', 'Repository unlinked.');
    }
}
