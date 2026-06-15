<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProjectTool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_approver_can_manage_tools(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $owner->id]);
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p', 'team_id' => $team->id]);
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $payload = ['kind' => ProjectTool::KIND_PROGRAM, 'name' => 'pandoc', 'check' => 'pandoc --version', 'run' => 'apt-get install -y pandoc'];

        $this->actingAs($member)->post(route('tools.store', $project), $payload)->assertForbidden();
        $this->actingAs($owner)->post(route('tools.store', $project), $payload)->assertRedirect();

        $this->assertDatabaseHas('project_tools', ['project_id' => $project->id, 'name' => 'pandoc', 'kind' => 'program']);
    }

    public function test_manifest_returns_tools_for_an_accessible_project(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->tools()->create([
            'kind' => ProjectTool::KIND_COMMAND, 'name' => 'sentry-issue',
            'description' => 'Fetch a Sentry issue', 'run' => 'echo wrapper',
        ]);

        Sanctum::actingAs($user, ['agent']);
        $this->getJson(route('api.projects.tools', $project))
            ->assertOk()
            ->assertJsonPath('tools.0.name', 'sentry-issue')
            ->assertJsonPath('tools.0.kind', 'command')
            ->assertJsonPath('tools.0.run', 'echo wrapper');
    }

    public function test_manifest_is_scoped_to_the_caller(): void
    {
        $stranger = User::factory()->create();
        $project = User::factory()->create()->projects()->create(['name' => 'X', 'slug' => 'x']);

        Sanctum::actingAs($stranger, ['agent']);
        $this->getJson(route('api.projects.tools', $project))->assertForbidden();
    }
}
