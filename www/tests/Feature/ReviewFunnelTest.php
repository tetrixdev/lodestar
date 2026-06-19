<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
