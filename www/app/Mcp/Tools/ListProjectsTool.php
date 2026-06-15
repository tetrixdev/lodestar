<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('List your projects with their linked repositories, plus your GitHub connections — so you know what exists and what to link a repo through.')]
#[Name('list_projects')]
class ListProjectsTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $user = $this->currentUser($request);

        // Owned + team projects, via the central access rule.
        $projects = Project::accessibleBy($user)->with('repositories:id,full_name,default_branch')->get();

        return Response::json([
            'projects' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'repositories' => $p->repositories->map(fn ($r) => [
                    'full_name' => $r->full_name,
                    'default_branch' => $r->default_branch,
                ])->all(),
            ])->all(),
            'github_connections' => $user->githubConnections->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->label,
                'login' => $c->github_login,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
