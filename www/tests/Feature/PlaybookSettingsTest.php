<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Playbook;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybookSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_shows_a_phase_card_per_phase_and_the_context_picker(): void
    {
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('playbooks.index'))
            ->assertOk()
            ->assertSee('Showing playbooks for')          // the single context picker
            ->assertSee('Just me')                        // the default "just me" context option
            ->assertSee('Propose a change / add a layer') // the create/propose affordance
            ->assertSee('Develop')                        // a phase card label
            ->assertSee('Preview prompt')                 // each composed phase is previewable
            ->assertSee('Named playbooks');               // the named-playbook group
    }

    public function test_overview_previewable_prompt_shows_the_composed_body(): void
    {
        // The composed prompt text is rendered on the page (previewable), not just
        // a list of scope badges.
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'Mine',
        ]);
        $slot->publish('Mine', null, 'RUN THE LINTER FIRST', $user);

        $this->actingAs($user)->get(route('playbooks.index'))
            ->assertOk()
            ->assertSee('RUN THE LINTER FIRST'); // the actual composed body is previewable inline
    }

    public function test_a_user_can_add_a_personal_layer_from_the_overview_form(): void
    {
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();

        // The overview's propose form posts a brand-new personal layer into existence.
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL,
            'key' => 'develop',
            'title' => 'My develop additions',
            'body' => 'ALWAYS run the linter.',

        ])->assertRedirect();

        $slot = $user->playbooks()->where('key', 'develop')->sole();
        $this->assertSame(Playbook::SCOPE_PERSONAL, $slot->scope);
        $this->assertStringContainsString('ALWAYS run the linter.', Playbook::compose($user, null, 'develop')['body']);
    }

    public function test_overview_scopes_the_list_to_the_chosen_context(): void
    {
        // The context picker IS the filter: a project layer only appears when that
        // project's context is selected, not in the "just me" view.
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Acme', 'owner_user_id' => $user->id]);
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket', 'team_id' => $team->id]);
        $projSlot = $project->playbooks()->create([
            'scope' => Playbook::SCOPE_PROJECT, 'key' => 'develop', 'title' => 'Rocket dev',
        ]);
        $projSlot->publish('Rocket dev', null, 'body', $user);

        // "Just me" context: the project layer's row is not listed.
        $this->actingAs($user)->get(route('playbooks.index'))
            ->assertOk()
            ->assertDontSee(route('playbooks.show', $projSlot));

        // That project's context: the project layer's row appears.
        $this->actingAs($user)->get(route('playbooks.index', ['context' => $project->id]))
            ->assertOk()
            ->assertSee(route('playbooks.show', $projSlot));
    }

    public function test_a_named_playbook_is_listed_in_its_named_group(): void
    {
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'my-recipe',
            'title' => 'My recipe',
        ]);
        $slot->publish('My recipe', 'when to use it', 'body', $user);

        $this->actingAs($user)->get(route('playbooks.index'))
            ->assertOk()
            ->assertSee('Named playbooks')
            ->assertSee(route('playbooks.show', $slot)); // listed as a reachable named playbook
    }

    public function test_slot_detail_shows_versions_active_body_and_a_diff(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
            'title' => 'Mine',
        ]);
        $v1 = $slot->publish('Mine', null, "line one\nline two", $user);
        $v2 = $slot->publish('Mine', null, "line one\nline THREE", $user);

        $this->actingAs($user)->get(route('playbooks.show', $slot))
            ->assertOk()
            ->assertSee('Active version')
            ->assertSee('Version history')
            ->assertSee('Propose a change')
            ->assertSee('line THREE');

        // Diffing the two versions surfaces the changed line.
        $this->actingAs($user)->get(route('playbooks.show', ['playbook' => $slot->id, 'a' => $v1->id, 'b' => $v2->id]))
            ->assertOk()
            ->assertSee('line two')   // removed
            ->assertSee('line THREE'); // added
    }

    public function test_a_v1_proposal_can_be_approved_from_the_slot_page(): void
    {
        // A brand-new AI proposal is the slot's ONLY version — nothing to diff
        // against. It must still be approvable. Regression: the decide UI used to
        // live inside the "needs two versions" compare tool, so v1 proposals (the
        // shape every `remember` / `propose_playbook_change` creates) were unreachable.
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'Mine',
        ]);
        $v1 = $slot->submitVersion('Mine', null, 'AI-WRITTEN RULE', $user, byAi: true);

        $this->assertTrue($v1->isProposed());
        $this->assertSame(1, $slot->versions()->count());

        // The slot page surfaces the pending proposal with a working approve action.
        $this->actingAs($user)->get(route('playbooks.show', $slot))
            ->assertOk()
            ->assertSee('Pending proposal')
            ->assertSee('AI-WRITTEN RULE')
            ->assertSee(route('playbooks.versions.approve', $v1));

        $this->actingAs($user)->post(route('playbooks.versions.approve', $v1))->assertRedirect();
        $this->assertTrue($v1->fresh()->isActive());
    }

    public function test_overview_previews_composition_for_a_chosen_project(): void
    {
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Acme', 'owner_user_id' => $user->id]);
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket', 'team_id' => $team->id]);

        // A project-scope layer only shows up when composing in that project's context.
        $slot = $project->playbooks()->create([
            'scope' => Playbook::SCOPE_PROJECT, 'key' => 'develop', 'title' => 'Rocket dev',
        ]);
        $slot->publish('Rocket dev', null, 'PROJECT-RULE', $user);

        // Plain view (just me) does not reach the project layer.
        $plain = Playbook::compose($user, null, 'develop');
        $this->assertStringNotContainsString('PROJECT-RULE', $plain['body']);

        // Selecting the project context surfaces it.
        $this->actingAs($user)->get(route('playbooks.index', ['context' => $project->id]))
            ->assertOk()
            ->assertSee('Rocket')
            ->assertSee('PROJECT-RULE'); // its composed body is previewable in context
        $this->assertStringContainsString('PROJECT-RULE', Playbook::compose($user, $project, 'develop')['body']);
    }

    public function test_a_stranger_cannot_view_a_personal_slot(): void
    {
        $owner = User::factory()->create();
        $slot = $owner->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
            'title' => 'theirs',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('playbooks.show', $slot))
            ->assertForbidden();
    }
}
