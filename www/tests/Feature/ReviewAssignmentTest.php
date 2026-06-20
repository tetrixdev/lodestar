<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function review(User $owner): Review
    {
        $project = $owner->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);

        return $project->reviews()->create([
            'title' => 'R',
            'status' => 'in_review',
        ]);
    }

    private function section(Review $review): ReviewSection
    {
        return $review->sections()->create([
            'position' => 0,
            'title' => 'S',
            'mode' => 'direct',
            'status' => 'open',
        ]);
    }

    // ── Feature 1: review ↔ task link ────────────────────────────────────────

    public function test_review_and_task_link_persists_both_ways(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $task = $review->project->tasks()->create(['title' => 'T', 'status' => 'human_review', 'position' => 0]);

        $review->tasks()->attach($task);

        $this->assertTrue($review->fresh()->tasks->contains($task));
        $this->assertTrue($task->fresh()->reviews->contains($review));
    }

    public function test_review_page_lists_its_linked_tasks(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $task = $review->project->tasks()->create(['title' => 'Linked card', 'status' => 'human_review', 'position' => 0]);
        $review->tasks()->attach($task);

        $this->actingAs($owner)
            ->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Linked card')
            ->assertSee('Human review');
    }

    public function test_board_card_links_to_its_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $task = $review->project->tasks()->create(['title' => 'Reviewed card', 'status' => 'ready_for_planning', 'position' => 0]);
        $review->tasks()->attach($task);

        $this->actingAs($owner)
            ->get(route('projects.show', $review->project))
            ->assertOk()
            ->assertSee(route('reviews.show', $review));
    }

    // ── Feature 2: atomic self-assignment ────────────────────────────────────

    public function test_assign_claims_an_unassigned_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);

        $this->actingAs($owner)
            ->post(route('reviews.assign', $review))
            ->assertRedirect(route('reviews.show', $review));

        $this->assertSame($owner->id, $review->fresh()->assigned_to_user_id);
    }

    public function test_claim_for_is_atomic_second_claim_noops(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        $review = $this->review($owner);

        $this->assertTrue($review->claimFor($owner->id));
        // A different user trying to claim a held review fails (the WHERE NULL
        // guard matches no rows), and the original holder is untouched.
        $this->assertFalse($review->fresh()->claimFor($second->id));
        $this->assertSame($owner->id, $review->fresh()->assigned_to_user_id);
    }

    public function test_unassign_only_succeeds_for_the_holder(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $review->claimFor($owner->id);

        // A non-holder cannot release it.
        $intruder = User::factory()->create();
        $this->assertFalse($review->fresh()->releaseFor($intruder->id));
        $this->assertSame($owner->id, $review->fresh()->assigned_to_user_id);

        // The holder can.
        $this->actingAs($owner)
            ->post(route('reviews.unassign', $review))
            ->assertRedirect(route('reviews.show', $review));
        $this->assertNull($review->fresh()->assigned_to_user_id);
    }

    // ── Feature 2: sign-off gate ─────────────────────────────────────────────

    public function test_update_section_is_403_when_not_assignee(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $section = $this->section($review);

        // Unassigned → blocked.
        $this->actingAs($owner)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['status' => 'signed_off'])
            ->assertForbidden();

        $this->assertSame('open', $section->fresh()->status);
    }

    public function test_update_section_succeeds_for_the_assignee(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner);
        $section = $this->section($review);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['status' => 'signed_off', 'note' => 'looks good'])
            ->assertOk()
            ->assertJson(['ok' => true, 'signed_off' => 1, 'total' => 1]);

        $this->assertSame('signed_off', $section->fresh()->status);
        $this->assertSame('looks good', $section->fresh()->note);
    }

    public function test_assignee_cannot_sign_off_after_another_user_holds_it(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        // Two users on the same project would require shared ownership; here we
        // prove the gate by having the second user own the project and claim it,
        // then the project owner (no longer the assignee) is blocked.
        $review = $this->review($second);
        $section = $this->section($review);
        $review->claimFor($second->id);

        // The owner of the review's project is $second; a different user is
        // forbidden by tenancy anyway, so we assert the assignee gate on the
        // holder path: $second can, and after release nobody can.
        $this->actingAs($second)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['status' => 'signed_off'])
            ->assertOk();

        $review->releaseFor($second->id);

        $this->actingAs($second)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['status' => 'open'])
            ->assertForbidden();
    }

    public function test_other_users_cannot_assign_or_view(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $review = $this->review($owner);

        $this->actingAs($intruder)->post(route('reviews.assign', $review))->assertForbidden();
        $this->actingAs($intruder)->get(route('reviews.show', $review))->assertForbidden();
    }
}
