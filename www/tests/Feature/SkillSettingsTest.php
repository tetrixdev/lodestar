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

    public function test_overview_shows_effective_phases_and_the_layer_list(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('skills.index'))
            ->assertOk()
            ->assertSee('Effective prompts')
            ->assertSee('All skill layers')
            ->assertSee('Propose a change / add a layer') // the create/propose affordance
            ->assertSee('Develop')          // a phase label
            ->assertSee('develop');         // a system layer row (key)
    }

    public function test_a_user_can_add_a_personal_layer_from_the_overview_form(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        // The overview's propose form posts a brand-new personal layer into existence.
        $this->actingAs($user)->post(route('skills.propose'), [
            'scope' => Skill::SCOPE_PERSONAL,
            'key' => 'develop',
            'title' => 'My develop additions',
            'body' => 'ALWAYS run the linter.',
            'mode' => Skill::MODE_APPEND,
        ])->assertRedirect();

        $slot = $user->skills()->where('key', 'develop')->sole();
        $this->assertSame(Skill::SCOPE_PERSONAL, $slot->scope);
        $this->assertStringContainsString('ALWAYS run the linter.', Skill::compose($user, null, 'develop')['body']);
    }

    public function test_overview_filters_by_scope(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();
        $slot = $user->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'my-recipe',
            'mode' => Skill::MODE_APPEND, 'title' => 'My recipe',
        ]);
        $slot->publish('My recipe', 'body', $user);
        $systemSlot = Skill::slotFor(Skill::SCOPE_SYSTEM, null, 'develop');

        // Personal filter lists the personal slot row, not the system slot row.
        $this->actingAs($user)->get(route('skills.index', ['scope' => Skill::SCOPE_PERSONAL]))
            ->assertOk()
            ->assertSee(route('skills.show', $slot))
            ->assertDontSee(route('skills.show', $systemSlot));
    }

    public function test_slot_detail_shows_versions_active_body_and_a_diff(): void
    {
        $user = User::factory()->create();
        $slot = $user->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'develop',
            'mode' => Skill::MODE_APPEND, 'title' => 'Mine',
        ]);
        $v1 = $slot->publish('Mine', "line one\nline two", $user);
        $v2 = $slot->publish('Mine', "line one\nline THREE", $user);

        $this->actingAs($user)->get(route('skills.show', $slot))
            ->assertOk()
            ->assertSee('Active version')
            ->assertSee('Version history')
            ->assertSee('Propose a change')
            ->assertSee('line THREE');

        // Diffing the two versions surfaces the changed line.
        $this->actingAs($user)->get(route('skills.show', ['skill' => $slot->id, 'a' => $v1->id, 'b' => $v2->id]))
            ->assertOk()
            ->assertSee('line two')   // removed
            ->assertSee('line THREE'); // added
    }

    public function test_a_stranger_cannot_view_a_personal_slot(): void
    {
        $owner = User::factory()->create();
        $slot = $owner->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'develop',
            'mode' => Skill::MODE_APPEND, 'title' => 'theirs',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('skills.show', $slot))
            ->assertForbidden();
    }
}
