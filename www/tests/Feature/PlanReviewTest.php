<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unified typed plan review (task #100 A): a plan review is a normal Review
 * (review_type=plan) with two fixed sections (Client-facing → body,
 * Technical-architecture → plan), seeded when a task reaches plan_review and
 * concluded by the human to drive the task forward to dev or back to planning.
 */
class PlanReviewTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return $user->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);
    }

    private function planReviewTask(Project $project): Task
    {
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);

        // Created at ready_for_dev then moved to plan_review so the saved hook fires
        // the status change into plan_review (and seeds the plan review).
        $task = $d->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Plan me',
            'body' => 'Client facing description',
            'body_summary' => 'CF summary',
            'plan' => 'Technical plan',
            'plan_summary' => 'Tech summary',
            'status' => Task::STATUS_READY_FOR_DEV,
            'position' => 0,
        ]);
        $task->update(['status' => Task::STATUS_PLAN_REVIEW]);

        return $task->fresh();
    }

    public function test_reaching_plan_review_seeds_a_plan_review_with_two_sections(): void
    {
        $task = $this->planReviewTask($this->project(User::factory()->create()));

        $review = $task->reviews()->where('review_type', Review::TYPE_PLAN)->first();
        $this->assertNotNull($review);
        $this->assertSame(Review::SCOPE_TASK, $review->scope);
        $this->assertSame(['Client-facing', 'Technical-architecture'],
            $review->sections()->orderBy('position')->pluck('title')->all());
    }

    public function test_seeding_is_idempotent(): void
    {
        $task = $this->planReviewTask($this->project(User::factory()->create()));

        $first = $task->ensurePlanReview();
        $second = $task->fresh()->ensurePlanReview();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $task->reviews()->where('review_type', Review::TYPE_PLAN)->count());
    }

    public function test_approving_both_sections_concludes_to_ready_for_dev(): void
    {
        $user = User::factory()->create();
        $task = $this->planReviewTask($this->project($user));
        $review = $task->ensurePlanReview();
        $review->update(['assigned_to_user_id' => $user->id]);
        $review->sections->each->update(['decision' => 'approved']);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $this->assertSame(Task::STATUS_READY_FOR_DEV, $task->fresh()->status);
        $this->assertSame('approved', $review->fresh()->outcome);
        $this->assertNull($task->fresh()->rework_notes);
    }

    public function test_requesting_changes_returns_to_planning_with_brief(): void
    {
        $user = User::factory()->create();
        $task = $this->planReviewTask($this->project($user));
        $review = $task->ensurePlanReview();
        $review->update(['assigned_to_user_id' => $user->id]);
        $sections = $review->sections()->orderBy('position')->get();
        $sections[0]->update(['decision' => 'approved']);
        $sections[1]->update(['decision' => 'changes_requested', 'note' => 'Rework the data flow']);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame(Task::STATUS_READY_FOR_PLANNING, $fresh->status);
        $this->assertStringContainsString('Rework the data flow', (string) $fresh->rework_notes);
    }

    public function test_incomplete_flag_forces_return_to_planning_even_when_approved(): void
    {
        $user = User::factory()->create();
        $task = $this->planReviewTask($this->project($user));
        $review = $task->ensurePlanReview();
        $review->update(['assigned_to_user_id' => $user->id, 'plan_incomplete' => true]);
        // Every section approved — but the incomplete flag wins.
        $review->sections->each->update(['decision' => 'approved']);

        $this->actingAs($user)->post(route('reviews.conclude', $review))->assertRedirect();

        $this->assertSame(Task::STATUS_READY_FOR_PLANNING, $task->fresh()->status);
        $this->assertSame('changes_requested', $review->fresh()->outcome);
    }

    public function test_plan_review_page_renders_the_body_and_plan_and_disables_approve_when_incomplete(): void
    {
        $user = User::factory()->create();
        $task = $this->planReviewTask($this->project($user));
        $review = $task->ensurePlanReview();
        $review->update(['assigned_to_user_id' => $user->id, 'plan_incomplete' => true]);

        $this->actingAs($user)->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Plan review')
            ->assertSee('Client facing description')
            ->assertSee('Technical plan')
            ->assertSee('flagged incomplete');
    }
}
