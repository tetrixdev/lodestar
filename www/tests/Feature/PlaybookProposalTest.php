<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\ProposePlaybookChangeTool;
use App\Mcp\Tools\RememberTool;
use App\Models\Playbook;
use App\Models\PlaybookVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change control: anyone in a scope may propose; only an approver makes it live;
 * an AI (MCP) can only ever propose — never approve, not even its own personal
 * layer.
 */
class PlaybookProposalTest extends TestCase
{
    use RefreshDatabase;

    // ── Compare-and-swap: you must read the current version before editing ──────

    public function test_proposing_onto_an_existing_playbook_requires_the_current_hash(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create(['scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'Mine']);
        $v1 = $slot->publish('Mine', null, 'v1 body', $user); // active v1
        $v1Hash = $slot->currentHash();

        // Brand-new slot needs no hash, but an existing one does: a blind propose
        // (no base_hash) is refused and handed the current content + hash.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
                'title' => 'Mine', 'body' => 'blind edit',
            ])
            ->assertOk()
            ->assertSee('base_hash is required')
            ->assertSee($v1Hash); // the current hash is handed back to read

        // With the right hash it goes through (as a proposal) and returns a NEW hash.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
                'title' => 'Mine', 'body' => 'good edit', 'base_hash' => $v1Hash,
            ])
            ->assertOk()
            ->assertSee('"status":"proposed"');

        $newHash = $slot->fresh()->currentHash();
        $this->assertNotSame($v1Hash, $newHash); // the token moved

        // Re-using the now-stale original hash is rejected — forces a re-read.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
                'title' => 'Mine', 'body' => 'second blind edit', 'base_hash' => $v1Hash,
            ])
            ->assertOk()
            ->assertSee('changed since you read it');

        // The fresh hash works — you can chain edits with the returned token.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
                'title' => 'Mine', 'body' => 'third edit', 'base_hash' => $newHash,
            ])
            ->assertOk()
            ->assertSee('"status":"proposed"');
    }

    public function test_creating_a_brand_new_layer_needs_no_hash(): void
    {
        $user = User::factory()->create();

        // Nothing to read yet — a first proposal on a fresh key is allowed without one.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop',
                'title' => 'Mine', 'body' => 'first',
            ])
            ->assertOk()
            ->assertSee('"status":"proposed"');
    }

    // ── MCP (AI) proposals ────────────────────────────────────────────────────

    public function test_mcp_proposal_is_always_proposed_even_on_own_personal(): void
    {
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL,
                'key' => 'develop',
                'title' => 'My develop',
                'body' => 'Run the linter.',
            ])
            ->assertOk()
            ->assertSee('"status":"proposed"');

        $version = PlaybookVersion::sole();
        $this->assertSame(PlaybookVersion::STATUS_PROPOSED, $version->status);
        $this->assertTrue($version->proposed_by_ai);

        // Nothing went live: composing the personal layer yields no active body.
        $this->assertSame('', Playbook::compose($user, null, 'develop')['body']);
    }

    public function test_mcp_cannot_propose_to_a_team_you_are_not_in(): void
    {
        $user = User::factory()->create();
        $strangerTeam = Team::create(['name' => 'X', 'owner_user_id' => User::factory()->create()->id]);

        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_TEAM,
                'team_id' => $strangerTeam->id,
                'key' => 'develop',
                'title' => 'sneaky',
                'body' => 'x',
            ])
            ->assertHasErrors();

        $this->assertSame(0, PlaybookVersion::count());
    }

    public function test_remember_appends_a_learning_as_a_proposal(): void
    {
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(RememberTool::class, ['learning' => 'Always run the linter before pushing.'])
            ->assertOk()
            ->assertSee('"status":"proposed"');

        $version = PlaybookVersion::sole();
        $this->assertSame(PlaybookVersion::STATUS_PROPOSED, $version->status);
        $this->assertTrue($version->proposed_by_ai);
        $this->assertStringContainsString('Always run the linter before pushing.', $version->body);

        // It's a personal main layer and is NOT live until approved.
        $this->assertSame('', Playbook::compose($user, null, 'main')['body']);
    }

    public function test_summary_is_required_for_named_playbooks_optional_for_phases(): void
    {
        $user = User::factory()->create();

        // Named playbook without a summary → rejected (web + MCP).
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'db-recipe', 'title' => 'Recipe', 'body' => 'do it',
        ])->assertSessionHasErrors('summary');

        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'db-recipe', 'title' => 'Recipe', 'body' => 'do it',
            ])->assertHasErrors();

        // With a summary → accepted, stored on the version.
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'db-recipe', 'title' => 'Recipe', 'summary' => 'Migrate the DB.', 'body' => 'do it',
        ])->assertRedirect();
        $this->assertSame('Migrate the DB.', PlaybookVersion::sole()->summary);

        // A phase playbook needs no summary.
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'Dev', 'body' => 'build',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_reserved_key_prefix_is_rejected_on_both_surfaces(): void
    {
        $user = User::factory()->create();

        // Web propose with an ls_ key → validation error, nothing created.
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL,
            'key' => 'ls_internal',
            'title' => 'x',
            'body' => 'y',
        ])->assertSessionHasErrors('key');

        // MCP propose with an ls_ key → rejected.
        LodestarServer::actingAs($user)
            ->tool(ProposePlaybookChangeTool::class, [
                'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'ls_internal', 'title' => 'x', 'body' => 'y',
            ])
            ->assertHasErrors();

        $this->assertSame(0, PlaybookVersion::count());
    }

    // ── Web (human) proposals ─────────────────────────────────────────────────

    public function test_a_human_self_approves_their_own_personal_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL,
            'key' => 'develop',
            'title' => 'My develop',
            'body' => 'Run the linter.',
        ])->assertRedirect();

        $version = PlaybookVersion::sole();
        $this->assertSame(PlaybookVersion::STATUS_ACTIVE, $version->status);
        $this->assertSame('Run the linter.', Playbook::compose($user, null, 'develop')['body']);
    }

    public function test_a_team_member_without_rights_proposes_and_an_approver_approves(): void
    {
        $approver = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $approver->id]); // owner can approve
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        // The plain member proposes — it lands proposed, NOT live.
        $this->actingAs($member)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_TEAM,
            'team_id' => $team->id,
            'key' => 'develop',
            'title' => 'Team develop',
            'body' => 'TEAM RULES',
        ])->assertRedirect();

        $version = PlaybookVersion::sole();
        $this->assertSame(PlaybookVersion::STATUS_PROPOSED, $version->status);

        // A non-approver cannot approve it.
        $this->actingAs($member)->post(route('playbooks.versions.approve', $version))->assertForbidden();

        // The approver makes it live.
        $this->actingAs($approver)->post(route('playbooks.versions.approve', $version))->assertRedirect();
        $this->assertSame(PlaybookVersion::STATUS_ACTIVE, $version->fresh()->status);
    }

    public function test_approving_archives_the_prior_active_version(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'plan', 'title' => 'p',
        ]);
        $old = $slot->publish('p', null, 'OLD', $user);
        $new = $slot->propose('p', null, 'NEW', $user, byAi: true);

        $this->actingAs($user)->post(route('playbooks.versions.approve', $new))->assertRedirect();

        $this->assertSame(PlaybookVersion::STATUS_ARCHIVED, $old->fresh()->status);
        $this->assertSame(PlaybookVersion::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame('NEW', Playbook::compose($user, null, 'plan')['body']);
    }

    public function test_approve_with_edits_publishes_amended_version_and_archives_the_proposal(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'plan', 'title' => 'p',
        ]);
        $slot->publish('p', null, 'V1 active', $user);
        $proposal = $slot->propose('p', null, 'AI proposed body', $user, byAi: true);

        $this->actingAs($user)->post(route('playbooks.versions.approveEdits', $proposal), [
            'title' => 'p', 'body' => 'AI proposed body, refined by me.',
        ])->assertRedirect();

        // The amended body is now live…
        $this->assertSame('AI proposed body, refined by me.', Playbook::compose($user, null, 'plan')['body']);
        // …the proposal is archived with provenance…
        $proposal->refresh();
        $this->assertSame(PlaybookVersion::STATUS_ARCHIVED, $proposal->status);
        $this->assertStringContainsString('Amended into v', $proposal->note);
        // …and exactly one active version remains.
        $this->assertSame(1, $slot->versions()->where('status', PlaybookVersion::STATUS_ACTIVE)->count());
    }

    public function test_rejecting_marks_a_proposal_rejected(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create([
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'plan', 'title' => 'p',
        ]);
        $proposal = $slot->propose('p', null, 'NOPE', $user, byAi: true);

        $this->actingAs($user)->post(route('playbooks.versions.reject', $proposal))->assertRedirect();
        $this->assertSame(PlaybookVersion::STATUS_REJECTED, $proposal->fresh()->status);
    }

    public function test_mode_is_versioned_and_changes_through_propose_approve(): void
    {
        $user = User::factory()->create();
        $slot = $user->playbooks()->create(['scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'd']);
        $slot->publish('d', null, 'BODY', $user, mode: Playbook::MODE_APPEND);

        // Composes as append.
        $this->assertSame(Playbook::MODE_APPEND, $slot->activeVersion->fresh()->mode);

        // Flipping to overwrite is a proposal carrying the new mode → self-approved live (own personal).
        $this->actingAs($user)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_PERSONAL, 'key' => 'develop', 'title' => 'd', 'body' => 'BODY', 'mode' => Playbook::MODE_OVERWRITE,
        ])->assertRedirect();

        $this->assertSame(Playbook::MODE_OVERWRITE, $slot->fresh()->activeVersion->mode);
    }

    public function test_mode_change_lands_proposed_for_a_non_approver(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'T', 'owner_user_id' => $owner->id]);
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);
        $slot = $team->playbooks()->create(['scope' => Playbook::SCOPE_TEAM, 'key' => 'develop', 'title' => 'd']);
        $slot->publish('d', null, 'BODY', $owner, mode: Playbook::MODE_APPEND);

        // A non-approver proposing overwrite lands PROPOSED — not live.
        $this->actingAs($member)->post(route('playbooks.propose'), [
            'scope' => Playbook::SCOPE_TEAM, 'team_id' => $team->id, 'key' => 'develop', 'title' => 'd', 'body' => 'BODY', 'mode' => Playbook::MODE_OVERWRITE,
        ])->assertRedirect();

        $this->assertSame(Playbook::MODE_APPEND, $slot->fresh()->activeVersion->mode); // still append
        $this->assertSame(1, $slot->versions()->where('status', PlaybookVersion::STATUS_PROPOSED)->where('mode', Playbook::MODE_OVERWRITE)->count());
    }
}
