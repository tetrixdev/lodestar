<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Repository;
use App\Services\GitHubAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Link a GitHub repository ("owner/name") to one of your projects, read through one of your GitHub connections. Verifies the connection can see the repo (and captures its default branch). If you have a single connection it is used by default. A repo can be linked to several projects.')]
#[Name('link_repository')]
class LinkRepositoryTool extends LodestarTool
{
    public function handle(Request $request, GitHubAccount $github): Response
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'repo' => ['required', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'connection' => ['nullable', 'string'], // id or label; optional if you have one
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project "'.$data['project'].'" belongs to you.');
        }

        $user = $this->currentUser($request);
        $connections = $user->githubConnections;

        if (! empty($data['connection'])) {
            $connection = $connections->first(
                fn ($c) => (string) $c->id === $data['connection'] || $c->label === $data['connection']
            );
        } else {
            $connection = $connections->count() === 1 ? $connections->first() : null;
        }
        if (! $connection) {
            return Response::error(
                $connections->isEmpty()
                    ? 'You have no GitHub connections yet — add one in Settings → GitHub first.'
                    : 'Specify which connection (id or label): '.$connections->pluck('label')->implode(', ').'.'
            );
        }

        $branch = $github->defaultBranch($connection->token, $data['repo']);
        if (! $branch) {
            return Response::error("The \"{$connection->label}\" connection can't see {$data['repo']} (wrong account or no access).");
        }

        $repository = Repository::firstOrCreate(
            ['github_connection_id' => $connection->id, 'full_name' => $data['repo']],
            ['default_branch' => $branch],
        );
        $project->repositories()->syncWithoutDetaching([$repository->id]);

        return Response::json([
            'linked' => $data['repo'],
            'default_branch' => $branch,
            'connection' => $connection->label,
            'project_repos' => $project->repositories()->pluck('full_name')->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug.')->required(),
            'repo' => $schema->string()->description('Repository as "owner/name".')->required(),
            'connection' => $schema->string()->description('Which GitHub connection (id or label) to read it through. Optional if you have exactly one.'),
        ];
    }
}
