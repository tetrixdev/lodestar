<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_connecting_github_verifies_the_token_and_stores_the_login(): void
    {
        Http::fake(['api.github.com/user' => Http::response(['login' => 'octo'], 200)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('github.store'), ['label' => 'work', 'token' => 'ghp_x'])
            ->assertRedirect(route('github.index'));

        $conn = $user->githubConnections()->sole();
        $this->assertSame('octo', $conn->github_login);
        $this->assertSame('ghp_x', $conn->token); // decrypts back via the cast
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        Http::fake(['api.github.com/user' => Http::response([], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('github.store'), ['label' => 'work', 'token' => 'bad'])
            ->assertSessionHasErrors('token');
        $this->assertSame(0, $user->githubConnections()->count());
    }

    public function test_linking_a_repo_verifies_access_and_attaches_it(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response(['default_branch' => 'main'], 200)]);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);

        $this->actingAs($user)->post(route('repositories.store', $project), [
            'github_connection_id' => $conn->id, 'full_name' => 'octo/app',
        ])->assertRedirect(route('repositories.index', $project));

        $repo = $project->repositories()->sole();
        $this->assertSame('octo/app', $repo->full_name);
        $this->assertSame('main', $repo->default_branch);
    }

    public function test_linking_a_repo_the_token_cannot_see_is_rejected(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);

        $this->actingAs($user)->post(route('repositories.store', $project), [
            'github_connection_id' => $conn->id, 'full_name' => 'octo/secret',
        ])->assertSessionHasErrors('full_name');
        $this->assertSame(0, $project->repositories()->count());
    }

    public function test_cannot_link_through_another_users_connection(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['default_branch' => 'main'], 200)]);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $strangerConn = User::factory()->create()->githubConnections()->create([
            'label' => 'x', 'token' => 't', 'github_login' => 'x',
        ]);

        $this->actingAs($user)->post(route('repositories.store', $project), [
            'github_connection_id' => $strangerConn->id, 'full_name' => 'x/y',
        ])->assertSessionHasErrors('github_connection_id');
        $this->assertSame(0, $project->repositories()->count());
    }

    public function test_the_settings_and_project_repo_pages_render(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 'work', 'token' => 't', 'github_login' => 'octo']);
        $repo = $conn->repositories()->create(['full_name' => 'octo/app', 'default_branch' => 'main']);
        $project->repositories()->attach($repo);

        $this->actingAs($user)->get(route('github.index'))->assertOk()->assertSee('octo');
        $this->actingAs($user)->get(route('repositories.index', $project))->assertOk()->assertSee('octo/app');
    }

    public function test_repository_pages_require_project_ownership(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p']);

        $this->actingAs(User::factory()->create())
            ->get(route('repositories.index', $project))
            ->assertForbidden();
    }
}
