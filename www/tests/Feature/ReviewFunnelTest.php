<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AdvanceDeliverableTool;
use App\Mcp\Tools\CreateReviewTool;
use App\Models\Deliverable;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function deliverableReview(User $user, string $deliverableStatus): Review
    {
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => $deliverableStatus]);
        $review = $project->reviews()->create([
            'title' => 'Deliverable review',
            'scope' => Review::SCOPE_DELIVERABLE,
            'review_type' => Review::TYPE_ARCHITECTURE,
            'deliverable_id' => $d->id,
            'status' => 'in_review',
            'assigned_to_user_id' => $user->id,
        ]);
        $review->sections()->create(['title' => 'S', 'mode' => 'behavioural', 'position' => 0, 'decision' => 'approved', 'status' => 'signed_off']);

        return $review;
    }

    public function test_approving_architecture_review_advances_deliverable_to_functional_sanity(): void
    {
        $user = User::factory()->create();
        $review = $this->deliverableReview($user, Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $this->assertSame(Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW, $review->deliverable->fresh()->status);
        $this->assertSame('approved', $review->fresh()->outcome);
    }

    public function test_approving_final_functional_review_marks_deliverable_approved(): void
    {
        $user = User::factory()->create();
        $review = $this->deliverableReview($user, Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $this->assertSame(Deliverable::STATUS_APPROVED, $review->deliverable->fresh()->status);
    }

    public function test_changes_requested_sends_deliverable_back_to_building(): void
    {
        $user = User::factory()->create();
        $review = $this->deliverableReview($user, Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW);
        $review->sections()->first()->update(['decision' => 'changes_requested', 'note' => 'fix the thing']);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $this->assertSame(Deliverable::STATUS_BUILDING, $review->deliverable->fresh()->status);
        $this->assertSame('changes_requested', $review->fresh()->outcome);
    }

    public function test_create_review_makes_a_deliverable_scoped_architecture_review(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        // No branch/base on the deliverable → no GitHub fetch, just the typed review.
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_AI_REVIEW]);

        LodestarServer::actingAs($user)
            ->tool(CreateReviewTool::class, ['project' => 'p', 'title' => 'Arch', 'scope' => 'deliverable', 'deliverable' => $d->id])
            ->assertOk()
            ->assertSee('"scope":"deliverable"')
            ->assertSee('"review_type":"architecture"');

        $this->assertDatabaseHas('reviews', ['deliverable_id' => $d->id, 'scope' => 'deliverable', 'review_type' => 'architecture']);
    }

    public function test_create_review_makes_a_functional_task_review(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(CreateReviewTool::class, ['project' => 'p', 'title' => 'Func', 'review_type' => 'functional'])
            ->assertOk()
            ->assertSee('"review_type":"functional"');
    }

    public function test_flag_stale_sections_marks_only_sections_covering_changed_files(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft']);
        $fileA = $review->files()->create(['path' => 'a.php', 'status' => 'modified', 'position' => 0]);
        $review->files()->create(['path' => 'b.php', 'status' => 'modified', 'position' => 1]);
        $touched = $review->sections()->create(['title' => 'Touched', 'mode' => 'direct', 'position' => 0, 'decision' => 'approved']);
        $untouched = $review->sections()->create(['title' => 'Untouched', 'mode' => 'direct', 'position' => 1, 'decision' => 'approved']);
        $touched->files()->attach($fileA->id);

        $flagged = $review->flagStaleSections(['a.php'], 'a.php changed');

        $this->assertSame(1, $flagged);
        $this->assertTrue($touched->fresh()->stale);
        $this->assertNull($touched->fresh()->decision);   // reset for re-review
        $this->assertFalse($untouched->fresh()->stale);   // kept its decision
        $this->assertSame('approved', $untouched->fresh()->decision);
    }

    public function test_deliverable_coverage_gate_blocks_human_review_until_files_covered(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_AI_REVIEW]);
        $review = $project->reviews()->create(['title' => 'R', 'scope' => Review::SCOPE_DELIVERABLE, 'deliverable_id' => $d->id, 'status' => 'draft']);
        $file = $review->files()->create(['path' => 'a.php', 'status' => 'modified', 'position' => 0]);

        // Uncovered file → gate blocks the move to human architecture review.
        LodestarServer::actingAs($user)
            ->tool(AdvanceDeliverableTool::class, ['deliverable_id' => $d->id, 'to' => Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW])
            ->assertHasErrors();
        $this->assertSame(Deliverable::STATUS_AI_REVIEW, $d->fresh()->status);

        // Cover it → the move is allowed.
        $review->sections()->create(['title' => 'S', 'mode' => 'direct', 'position' => 0])->files()->attach($file->id);
        LodestarServer::actingAs($user)
            ->tool(AdvanceDeliverableTool::class, ['deliverable_id' => $d->id, 'to' => Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW])
            ->assertOk();
        $this->assertSame(Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW, $d->fresh()->status);
    }
}
