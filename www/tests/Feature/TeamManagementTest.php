<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Teams UI: creating teams, managing members by email, the
 * personal-instructions toggle, assigning projects to teams (which grants
 * teammates access), and the owner-only guards.
 */
class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── creating a team ──────────────────────────────────────────────────────

    public function test_creating_a_team_auto_adds_the_owner_as_a_member(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('teams.store'), ['name' => 'Acme'])
            ->assertRedirect();

        $team = Team::where('name', 'Acme')->firstOrFail();
        $this->assertSame($owner->id, $team->owner_user_id);
        $this->assertTrue($team->members()->whereKey($owner->id)->exists());
        $this->assertTrue($team->canApprovePrompts($owner));
    }

    public function test_team_index_lists_the_users_teams(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Visible Crew', 'owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->get(route('teams.index'))
            ->assertOk()
            ->assertSee('Visible Crew');
    }

    // ── members ──────────────────────────────────────────────────────────────

    public function test_owner_adds_an_existing_user_by_email(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['email' => 'mate@example.com']);
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('teams.members.add', $team), [
                'email' => 'mate@example.com',
                'role' => 'member',
                'can_approve_prompts' => '1',
            ])
            ->assertRedirect(route('teams.show', $team));

        $this->assertTrue($team->members()->whereKey($teammate->id)->exists());
        $this->assertTrue($team->canApprovePrompts($teammate));
    }

    public function test_adding_a_non_existent_email_errors(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('teams.members.add', $team), [
                'email' => 'ghost@example.com',
                'role' => 'member',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $team->members()->count()); // just the owner
    }

    public function test_owner_can_remove_a_member_but_not_themselves(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($teammate->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $this->actingAs($owner)
            ->delete(route('teams.members.remove', [$team, $teammate]))
            ->assertRedirect();
        $this->assertFalse($team->members()->whereKey($teammate->id)->exists());

        // The owner cannot be removed.
        $this->actingAs($owner)
            ->delete(route('teams.members.remove', [$team, $owner]))
            ->assertForbidden();
        $this->assertTrue($team->members()->whereKey($owner->id)->exists());
    }

    public function test_a_non_owner_cannot_manage_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $this->actingAs($member)
            ->post(route('teams.members.add', $team), [
                'email' => $owner->email,
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    // ── settings ─────────────────────────────────────────────────────────────

    public function test_owner_can_toggle_allow_personal_instructions(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $this->assertFalse((bool) $team->allow_personal_instructions);

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), [
                'name' => 'Crew',
                'allow_personal_instructions' => '1',
            ])
            ->assertRedirect();
        $this->assertTrue($team->fresh()->allow_personal_instructions);

        // Unchecked → false.
        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Crew'])
            ->assertRedirect();
        $this->assertFalse($team->fresh()->allow_personal_instructions);
    }

    public function test_a_non_owner_cannot_edit_team_settings(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => 'Hijacked'])
            ->assertForbidden();
        $this->assertSame('Crew', $team->fresh()->name);
    }

    // ── project ↔ team assignment ────────────────────────────────────────────

    public function test_assigning_a_project_to_a_team_grants_a_teammate_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $project = $owner->projects()->create(['name' => 'Mine', 'slug' => 'mine-'.uniqid()]);

        // Before assignment: the teammate cannot reach it.
        $this->actingAs($member)->get(route('projects.show', $project))->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('projects.update', $project), [
                'name' => 'Mine',
                'team_id' => (string) $team->id,
            ])
            ->assertRedirect(route('projects.settings', $project));

        $this->assertSame($team->id, $project->fresh()->team_id);
        $this->actingAs($member)->get(route('projects.show', $project))->assertOk();
    }

    public function test_owner_cannot_assign_a_project_to_a_team_they_are_not_in(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $otherTeam = Team::create(['name' => 'Other', 'owner_user_id' => $stranger->id]);
        $project = $owner->projects()->create(['name' => 'Mine', 'slug' => 'mine-'.uniqid()]);

        $this->actingAs($owner)
            ->patch(route('projects.update', $project), [
                'name' => 'Mine',
                'team_id' => (string) $otherTeam->id,
            ])
            ->assertSessionHasErrors('team_id');

        $this->assertNull($project->fresh()->team_id);
    }

    public function test_personal_project_settings_show_owner_only_for_approvers(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'Solo', 'slug' => 'solo-'.uniqid()]);
        $this->assertNull($project->team_id);

        $this->actingAs($owner)
            ->get(route('projects.settings', $project))
            ->assertOk()
            ->assertSee('personal project')
            ->assertSee('Personal (no team)');
    }

    public function test_owner_can_add_a_team_member_as_a_project_approver(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);
        $project = $owner->projects()->create([
            'name' => 'Shared', 'slug' => 'shared-'.uniqid(), 'team_id' => $team->id,
        ]);

        $this->actingAs($owner)
            ->post(route('projects.approvers.add', $project), [
                'user_id' => (string) $member->id,
                'can_approve_prompts' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($project->canApprovePrompts($member));
    }
}
