<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\LinkRepositoryTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\UnlinkRepositoryTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpRepoToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_repository_uses_the_sole_connection_and_attaches(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response(['default_branch' => 'main'], 200)]);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);

        LodestarServer::actingAs($user)->tool(LinkRepositoryTool::class, [
            'project' => 'p', 'repo' => 'octo/app',
        ])->assertOk()->assertSee('octo/app');

        $this->assertSame('octo/app', $project->repositories()->sole()->full_name);
    }

    public function test_link_repository_requires_a_connection(): void
    {
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)->tool(LinkRepositoryTool::class, [
            'project' => 'p', 'repo' => 'octo/app',
        ])->assertHasErrors();
    }

    public function test_link_repository_rejects_a_repo_the_connection_cannot_see(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);

        LodestarServer::actingAs($user)->tool(LinkRepositoryTool::class, [
            'project' => 'p', 'repo' => 'octo/secret',
        ])->assertHasErrors();
        $this->assertSame(0, $project->repositories()->count());
    }

    public function test_unlink_repository_detaches(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 'w', 'token' => 't', 'github_login' => 'o']);
        $repo = $conn->repositories()->create(['full_name' => 'o/r', 'default_branch' => 'main']);
        $project->repositories()->attach($repo);

        LodestarServer::actingAs($user)->tool(UnlinkRepositoryTool::class, [
            'project' => 'p', 'repo' => 'o/r',
        ])->assertOk();
        $this->assertSame(0, $project->repositories()->count());
    }

    public function test_list_projects_shows_repos_and_connections(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);
        $repo = $conn->repositories()->create(['full_name' => 'octo/app', 'default_branch' => 'main']);
        $project->repositories()->attach($repo);

        LodestarServer::actingAs($user)->tool(ListProjectsTool::class, [])
            ->assertOk()
            ->assertSee('octo/app')
            ->assertSee('work');
    }
}
