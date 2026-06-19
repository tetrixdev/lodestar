<?php

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverableLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return $user->projects()->create([
            'name' => 'Demo',
            'slug' => 'demo-'.uniqid(),
        ]);
    }

    private function deliverable(Project $project, string $status = Deliverable::STATUS_NEW): Deliverable
    {
        return $project->deliverables()->create([
            'title' => 'Ship v1',
            'status' => $status,
        ]);
    }

    private function task(Deliverable $d, array $attrs = []): Task
    {
        return $d->tasks()->create(array_merge([
            'project_id' => $d->project_id,
            'title' => 'A task',
            'status' => Task::STATUS_NEW,
            'position' => 0,
        ], $attrs));
    }

    // ── lifecycle state machine ───────────────────────────────────────────────

    public function test_legal_and_illegal_transitions(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_PLAN_REVIEW);

        $this->assertTrue($d->canTransitionTo(Deliverable::STATUS_BUILDING));            // approve
        $this->assertTrue($d->canTransitionTo(Deliverable::STATUS_READY_FOR_PLANNING));  // back
        $this->assertTrue($d->canTransitionTo(Deliverable::STATUS_CANCELLED));           // cancel
        $this->assertFalse($d->canTransitionTo(Deliverable::STATUS_AI_REVIEW));          // illegal jump
        $this->assertFalse($d->canTransitionTo(Deliverable::STATUS_DONE));
    }

    public function test_review_feedback_routes_back_to_building(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_AI_REVIEW);
        // feedback → building (corrective-task model: no separate deliverable dev cycle)
        $this->assertTrue($d->canTransitionTo(Deliverable::STATUS_BUILDING));
        // pass → architecture review
        $this->assertTrue($d->canTransitionTo(Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW));
    }

    public function test_cancelled_is_terminal(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_CANCELLED);
        $this->assertSame([], $d->allowedTransitions());
    }

    public function test_status_changed_at_is_stamped(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        $this->assertNotNull($d->status_changed_at);
    }

    // ── open-questions gate ───────────────────────────────────────────────────

    public function test_unanswered_question_blocks_plan_approval(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_PLAN_REVIEW);
        $q = $d->questions()->create(['question' => 'Which auth provider?', 'position' => 0]);

        $this->assertTrue($d->hasUnansweredQuestions());
        $this->assertFalse($d->canApprovePlan());

        $q->update(['answer' => 'Sanctum']);

        $this->assertTrue($q->isAnswered());
        $this->assertNotNull($q->fresh()->answered_at);
        $this->assertFalse($d->fresh()->hasUnansweredQuestions());
        $this->assertTrue($d->fresh()->canApprovePlan());
    }

    public function test_clearing_an_answer_unanswers_the_question(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_PLAN_REVIEW);
        $q = $d->questions()->create(['question' => 'X?', 'answer' => 'Y', 'position' => 0]);
        $this->assertTrue($q->isAnswered());

        $q->update(['answer' => null]);
        $this->assertNull($q->fresh()->answered_at);
    }

    // ── per-deliverable sub_id + branch conventions ───────────────────────────

    public function test_sub_id_is_assigned_sequentially_within_deliverable(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        $t1 = $this->task($d);
        $t2 = $this->task($d);
        $t3 = $this->task($d);

        $this->assertSame(1, $t1->sub_id);
        $this->assertSame(2, $t2->sub_id);
        $this->assertSame(3, $t3->sub_id);
    }

    public function test_branch_name_conventions(): void
    {
        $project = $this->project(User::factory()->create());
        $d = $project->deliverables()->create(['title' => 'Deliverable Workflow', 'status' => 'new']);
        $nested = $this->task($d, ['title' => 'Merge conflict resolver']);

        $this->assertSame(sprintf('D%06d-deliverable-workflow', $d->id), $d->branchName());
        $this->assertSame(
            sprintf('D%06d/T01-merge-conflict-resolver', $d->id),
            $nested->branchName(),
        );

        $standalone = $project->tasks()->create(['title' => 'Hotfix thing', 'status' => 'new', 'position' => 0]);
        $this->assertSame(sprintf('T%06d-hotfix-thing', $standalone->id), $standalone->branchName());
        $this->assertNull($standalone->sub_id);
    }

    // ── child-task completion ─────────────────────────────────────────────────

    public function test_all_tasks_complete_drives_building_exit(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_BUILDING);
        $this->assertFalse($d->allTasksComplete()); // no tasks yet → nothing to ship

        $t1 = $this->task($d);
        $t2 = $this->task($d);
        $this->assertFalse($d->allTasksComplete());

        $t1->update(['status' => Task::STATUS_DONE]);
        $t2->update(['status' => Task::STATUS_CANCELLED]);
        $this->assertTrue($d->fresh()->allTasksComplete());
    }

    // ── dirty-section watermark ───────────────────────────────────────────────

    public function test_mark_stale_resets_decision_and_records_change(): void
    {
        $project = $this->project(User::factory()->create());
        $d = $this->deliverable($project);
        $review = $project->reviews()->create([
            'title' => 'Deliverable architecture review',
            'scope' => Review::SCOPE_DELIVERABLE,
            'review_type' => Review::TYPE_ARCHITECTURE,
            'deliverable_id' => $d->id,
        ]);
        $section = $review->sections()->create([
            'title' => 'Review flow',
            'position' => 0,
            'mode' => 'behavioural',
            'decision' => 'approved',
        ]);

        $section->markStale('Task T03 touched app/Models/Review.php on merge');

        $fresh = $section->fresh();
        $this->assertNull($fresh->decision);
        $this->assertTrue($fresh->stale);
        $this->assertSame('Task T03 touched app/Models/Review.php on merge', $fresh->change_note);
    }
}
