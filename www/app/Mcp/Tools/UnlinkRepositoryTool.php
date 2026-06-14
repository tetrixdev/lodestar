<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Unlink a repository ("owner/name") from one of your projects. The repository row itself is left intact (it may be linked to other projects).')]
#[Name('unlink_repository')]
class UnlinkRepositoryTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'repo' => ['required', 'string'],
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project "'.$data['project'].'" belongs to you.');
        }

        $repository = $project->repositories()->where('full_name', $data['repo'])->first();
        if (! $repository) {
            return Response::error("\"{$data['repo']}\" is not linked to this project.");
        }

        $project->repositories()->detach($repository->id);

        return Response::json([
            'unlinked' => $data['repo'],
            'project_repos' => $project->repositories()->pluck('full_name')->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug.')->required(),
            'repo' => $schema->string()->description('Repository "owner/name" to unlink.')->required(),
        ];
    }
}
