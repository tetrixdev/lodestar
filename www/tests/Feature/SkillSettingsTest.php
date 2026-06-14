<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\User;
use Database\Seeders\SystemSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_skills_page_lists_the_system_skills(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('skills.index'))
            ->assertOk()
            ->assertSee('Develop a task')
            ->assertSee('Merge & deploy a task');
    }

    public function test_duplicating_a_system_skill_creates_an_editable_fork(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $system = Skill::currentSystem('develop');

        $this->actingAs($user)->post(route('skills.duplicate', $system))->assertRedirect();

        $fork = $user->skills()->sole();
        $this->assertSame(Skill::KIND_USER, $fork->kind);
        $this->assertSame('develop', $fork->key);
        $this->assertSame($system->version, $fork->source_version);
    }

    public function test_a_user_can_edit_their_fork_but_not_a_system_skill(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $system = Skill::currentSystem('develop');
        $fork = $user->skills()->create([
            'kind' => Skill::KIND_USER, 'key' => 'develop', 'version' => 1,
            'title' => 'mine', 'body' => 'x', 'source_version' => 1,
        ]);

        $this->actingAs($user)->patch(route('skills.update', $fork), [
            'title' => 'updated', 'body' => 'new body',
        ])->assertRedirect();
        $this->assertSame('updated', $fork->fresh()->title);

        // System skills are read-only.
        $this->actingAs($user)->patch(route('skills.update', $system), [
            'title' => 'hacked', 'body' => 'nope',
        ])->assertForbidden();
    }

    public function test_a_user_cannot_edit_another_users_fork(): void
    {
        $owner = User::factory()->create();
        $fork = $owner->skills()->create([
            'kind' => Skill::KIND_USER, 'key' => 'develop', 'version' => 1,
            'title' => 'theirs', 'body' => 'x', 'source_version' => 1,
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('skills.update', $fork), ['title' => 'x', 'body' => 'y'])
            ->assertForbidden();
    }
}
