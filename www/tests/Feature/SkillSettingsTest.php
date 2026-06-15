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

    public function test_the_skills_page_shows_the_composed_phases(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('skills.index'))
            ->assertOk()
            ->assertSee('Develop')
            ->assertSee('Merge & deploy')
            ->assertSee('layered'); // the explainer copy
    }

    public function test_a_personal_layer_appears_in_the_composed_prompt(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        $slot = $user->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'develop',
            'mode' => Skill::MODE_APPEND, 'title' => 'My extras',
        ]);
        $slot->publish('My extras', 'ALWAYS run the linter.', $user);

        $this->actingAs($user)->get(route('skills.index'))
            ->assertOk()
            ->assertSee('ALWAYS run the linter.');
    }
}
