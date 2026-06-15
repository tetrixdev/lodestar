<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\ProposeSkillChangeTool;
use App\Mcp\Tools\RememberTool;
use App\Models\Skill;
use App\Models\SkillVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change control: anyone in a scope may propose; only an approver makes it live;
 * an AI (MCP) can only ever propose — never approve, not even its own personal
 * layer.
 */
class SkillProposalTest extends TestCase
{
    use RefreshDatabase;

    // ── MCP (AI) proposals ────────────────────────────────────────────────────

    public function test_mcp_proposal_is_always_proposed_even_on_own_personal(): void
    {
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(ProposeSkillChangeTool::class, [
                'scope' => Skill::SCOPE_PERSONAL,
                'key' => 'develop',
                'title' => 'My develop',
                'body' => 'Run the linter.',
            ])
            ->assertOk()
            ->assertSee('"status":"proposed"');

        $version = SkillVersion::sole();
        $this->assertSame(SkillVersion::STATUS_PROPOSED, $version->status);
        $this->assertTrue($version->proposed_by_ai);

        // Nothing went live: composing the personal layer yields no active body.
        $this->assertSame('', Skill::compose($user, null, 'develop')['body']);
    }

    public function test_mcp_cannot_propose_to_a_team_you_are_not_in(): void
    {
        $user = User::factory()->create();
        $strangerTeam = Team::create(['name' => 'X', 'owner_user_id' => User::factory()->create()->id]);

        LodestarServer::actingAs($user)
            ->tool(ProposeSkillChangeTool::class, [
                'scope' => Skill::SCOPE_TEAM,
                'team_id' => $strangerTeam->id,
                'key' => 'develop',
                'title' => 'sneaky',
                'body' => 'x',
            ])
            ->assertHasErrors();

        $this->assertSame(0, SkillVersion::count());
    }

    public function test_remember_appends_a_learning_as_a_proposal(): void
    {
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(RememberTool::class, ['learning' => 'Always run the linter before pushing.'])
            ->assertOk()
            ->assertSee('"status":"proposed"');

        $version = SkillVersion::sole();
        $this->assertSame(SkillVersion::STATUS_PROPOSED, $version->status);
        $this->assertTrue($version->proposed_by_ai);
        $this->assertStringContainsString('Always run the linter before pushing.', $version->body);

        // It's a personal main layer and is NOT live until approved.
        $this->assertSame('', Skill::compose($user, null, 'main')['body']);
    }

    public function test_reserved_key_prefix_is_rejected_on_both_surfaces(): void
    {
        $user = User::factory()->create();

        // Web propose with an ls_ key → validation error, nothing created.
        $this->actingAs($user)->post(route('skills.propose'), [
            'scope' => Skill::SCOPE_PERSONAL,
            'key' => 'ls_internal',
            'title' => 'x',
            'body' => 'y',
        ])->assertSessionHasErrors('key');

        // MCP propose with an ls_ key → rejected.
        LodestarServer::actingAs($user)
            ->tool(ProposeSkillChangeTool::class, [
                'scope' => Skill::SCOPE_PERSONAL, 'key' => 'ls_internal', 'title' => 'x', 'body' => 'y',
            ])
            ->assertHasErrors();

        $this->assertSame(0, SkillVersion::count());
    }

    // ── Web (human) proposals ─────────────────────────────────────────────────

    public function test_a_human_self_approves_their_own_personal_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('skills.propose'), [
            'scope' => Skill::SCOPE_PERSONAL,
            'key' => 'develop',
            'title' => 'My develop',
            'body' => 'Run the linter.',
        ])->assertRedirect();

        $version = SkillVersion::sole();
        $this->assertSame(SkillVersion::STATUS_ACTIVE, $version->status);
        $this->assertSame('Run the linter.', Skill::compose($user, null, 'develop')['body']);
    }

    public function test_a_team_member_without_rights_proposes_and_an_approver_approves(): void
    {
        $approver = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $approver->id]); // owner can approve
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        // The plain member proposes — it lands proposed, NOT live.
        $this->actingAs($member)->post(route('skills.propose'), [
            'scope' => Skill::SCOPE_TEAM,
            'team_id' => $team->id,
            'key' => 'develop',
            'title' => 'Team develop',
            'body' => 'TEAM RULES',
        ])->assertRedirect();

        $version = SkillVersion::sole();
        $this->assertSame(SkillVersion::STATUS_PROPOSED, $version->status);

        // A non-approver cannot approve it.
        $this->actingAs($member)->post(route('skills.versions.approve', $version))->assertForbidden();

        // The approver makes it live.
        $this->actingAs($approver)->post(route('skills.versions.approve', $version))->assertRedirect();
        $this->assertSame(SkillVersion::STATUS_ACTIVE, $version->fresh()->status);
    }

    public function test_approving_archives_the_prior_active_version(): void
    {
        $user = User::factory()->create();
        $slot = $user->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'plan', 'mode' => Skill::MODE_APPEND, 'title' => 'p',
        ]);
        $old = $slot->publish('p', 'OLD', $user);
        $new = $slot->propose('p', 'NEW', $user, byAi: true);

        $this->actingAs($user)->post(route('skills.versions.approve', $new))->assertRedirect();

        $this->assertSame(SkillVersion::STATUS_ARCHIVED, $old->fresh()->status);
        $this->assertSame(SkillVersion::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame('NEW', Skill::compose($user, null, 'plan')['body']);
    }

    public function test_rejecting_marks_a_proposal_rejected(): void
    {
        $user = User::factory()->create();
        $slot = $user->skills()->create([
            'scope' => Skill::SCOPE_PERSONAL, 'key' => 'plan', 'mode' => Skill::MODE_APPEND, 'title' => 'p',
        ]);
        $proposal = $slot->propose('p', 'NOPE', $user, byAi: true);

        $this->actingAs($user)->post(route('skills.versions.reject', $proposal))->assertRedirect();
        $this->assertSame(SkillVersion::STATUS_REJECTED, $proposal->fresh()->status);
    }

    public function test_toggling_overwrite_mode_is_approver_only(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $owner->id]);
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $slot = $team->skills()->create([
            'scope' => Skill::SCOPE_TEAM, 'key' => 'develop', 'mode' => Skill::MODE_APPEND, 'title' => 'd',
        ]);

        $this->actingAs($member)->patch(route('skills.mode', $slot))->assertForbidden();
        $this->assertSame(Skill::MODE_APPEND, $slot->fresh()->mode);

        $this->actingAs($owner)->patch(route('skills.mode', $slot))->assertRedirect();
        $this->assertSame(Skill::MODE_OVERWRITE, $slot->fresh()->mode);
    }
}
