<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_setup_checklist_for_a_fresh_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('board'))
            ->assertOk()
            ->assertSee('Get set up')
            ->assertSee('Connect a GitHub account')
            ->assertSee('Create a project')
            ->assertSee('Link a repository to a project')
            ->assertSee('Connect a coding agent');
    }

    public function test_dashboard_hides_the_checklist_once_every_step_is_done(): void
    {
        $user = User::factory()->create();

        // 1. GitHub connection.
        $conn = $user->githubConnections()->create([
            'label' => 'work', 'token' => 't', 'github_login' => 'octo',
        ]);
        // 2. Project + 3. linked repo.
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $repo = $conn->repositories()->create(['full_name' => 'octo/app', 'default_branch' => 'main']);
        $project->repositories()->attach($repo);
        // 4. Agent token.
        $user->createToken('laptop', ['agent']);

        $this->actingAs($user)
            ->get(route('board'))
            ->assertOk()
            ->assertDontSee('Get set up');
    }

    public function test_settings_index_renders_and_links_to_the_sub_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee(route('profile.edit'))
            ->assertSee(route('agent-tokens.index'))
            ->assertSee(route('github.index'))
            ->assertSee(route('playbooks.index'));
    }

    public function test_settings_index_requires_auth(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    public function test_a_fresh_projects_index_shows_the_create_cta(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Create your first project');
    }
}
