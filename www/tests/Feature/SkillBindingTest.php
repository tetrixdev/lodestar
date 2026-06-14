<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\GetSkillTool;
use App\Models\Skill;
use App\Models\SkillBinding;
use App\Models\User;
use Database\Seeders\SystemSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillBindingTest extends TestCase
{
    use RefreshDatabase;

    private function fork(User $user, string $phase, string $body = 'custom prompt'): Skill
    {
        return $user->skills()->create([
            'kind' => Skill::KIND_USER, 'key' => $phase, 'version' => 1,
            'title' => 'My '.$phase, 'body' => $body, 'source_version' => 1,
        ]);
    }

    public function test_binding_a_phase_to_a_fork_resolves_and_serves_the_fork(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $fork = $this->fork($user, 'develop');

        $this->actingAs($user)->post(route('skills.bind'), [
            'phase' => 'develop', 'skill_id' => $fork->id,
        ])->assertRedirect(route('skills.index'));

        // resolve() returns the fork.
        $this->assertTrue(Skill::resolve($user, null, 'develop')->is($fork));

        // get_skill(phase) returns it too.
        LodestarServer::actingAs($user)
            ->tool(GetSkillTool::class, ['phase' => 'develop'])
            ->assertOk()
            ->assertSee('"kind":"user"')
            ->assertSee('custom prompt');
    }

    public function test_binding_to_the_current_system_skill_is_allowed(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $system = Skill::currentSystem('plan');

        $this->actingAs($user)->post(route('skills.bind'), [
            'phase' => 'plan', 'skill_id' => $system->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('skill_bindings', [
            'user_id' => $user->id, 'project_id' => null,
            'phase' => 'plan', 'skill_id' => $system->id,
        ]);
    }

    public function test_reset_removes_the_binding_and_falls_back_to_system(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $fork = $this->fork($user, 'develop');
        SkillBinding::create([
            'user_id' => $user->id, 'project_id' => null, 'phase' => 'develop', 'skill_id' => $fork->id,
        ]);

        $this->actingAs($user)->delete(route('skills.unbind', 'develop'))->assertRedirect();

        $this->assertDatabaseMissing('skill_bindings', [
            'user_id' => $user->id, 'project_id' => null, 'phase' => 'develop',
        ]);
        $this->assertTrue(Skill::resolve($user, null, 'develop')->isSystem());
    }

    public function test_bind_rejects_a_fork_the_user_does_not_own(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $otherFork = $this->fork(User::factory()->create(), 'develop');

        $this->actingAs($user)->post(route('skills.bind'), [
            'phase' => 'develop', 'skill_id' => $otherFork->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('skill_bindings', ['user_id' => $user->id]);
    }

    public function test_bind_rejects_a_skill_for_a_different_phase(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $planFork = $this->fork($user, 'plan');

        $this->actingAs($user)->post(route('skills.bind'), [
            'phase' => 'develop', 'skill_id' => $planFork->id,
        ])->assertForbidden();
    }

    public function test_the_skills_page_renders_the_per_phase_binding_controls(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $fork = $this->fork($user, 'develop');
        SkillBinding::create([
            'user_id' => $user->id, 'project_id' => null, 'phase' => 'develop', 'skill_id' => $fork->id,
        ]);

        $this->actingAs($user)->get(route('skills.index'))
            ->assertOk()
            ->assertSee('Your loop')
            ->assertSee('Reset to system')
            ->assertSee('Fork: My develop');
    }
}
