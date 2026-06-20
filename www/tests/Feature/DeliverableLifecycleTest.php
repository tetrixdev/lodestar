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
            'status' => Task::STATUS_READY_FOR_PLANNING,
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
        $this->assertFalse($d->canTransitionTo(Deliverable::STATUS_MERGED));
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

        // A second child gets the next sub_id and nests under the same deliverable.
        $second = $this->task($d, ['title' => 'Hotfix thing']);
        $this->assertSame(sprintf('D%06d/T02-hotfix-thing', $d->id), $second->branchName());
        $this->assertSame(2, $second->sub_id);
    }

    // ── child-task completion ─────────────────────────────────────────────────

    public function test_all_tasks_complete_drives_building_exit(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_BUILDING);
        $this->assertFalse($d->allTasksComplete()); // no tasks yet → nothing to ship

        $t1 = $this->task($d);
        $t2 = $this->task($d);
        $this->assertFalse($d->allTasksComplete());

        $t1->update(['status' => Task::STATUS_MERGED]);
        $t2->update(['status' => Task::STATUS_CANCELLED]);
        $this->assertTrue($d->fresh()->allTasksComplete());
    }

    // ── child-task lifecycle (no planning; functional gate) ───────────────────

    public function test_child_task_can_be_planned_and_uses_the_functional_gate(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        // A child CAN carry its own plan phase (planning happens per-task now).
        $child = $this->task($d, ['status' => Task::STATUS_PLAN_REVIEW]);
        $this->assertSame(Task::STATUS_PLAN_REVIEW, $child->fresh()->status);
        $this->assertTrue($child->canTransitionTo(Task::STATUS_READY_FOR_DEV)); // approve plan

        // It runs the full lifecycle; its human gate is the FUNCTIONAL review.
        $child->update(['status' => Task::STATUS_AI_REVIEW]);
        $this->assertTrue($child->fresh()->canTransitionTo(Task::STATUS_HUMAN_REVIEW));
        $this->assertSame('functional', $child->fresh()->humanGateType());
    }

    public function test_every_task_uses_the_functional_human_gate(): void
    {
        // No loose tasks: every task is a deliverable child, so the human gate is
        // always the functional review.
        $d = $this->deliverable($this->project(User::factory()->create()));
        $t = $this->task($d, ['title' => 'Child']);
        $this->assertTrue($t->isDeliverableChild());
        $this->assertSame('functional', $t->humanGateType());
    }

    public function test_creating_a_task_without_a_deliverable_is_rejected(): void
    {
        // deliverable_id is a required FK — a loose task cannot be created. The model's
        // creating hook dereferences the deliverable, so a null deliverable throws.
        $project = $this->project(User::factory()->create());

        $this->expectException(\Throwable::class);
        $project->tasks()->create(['title' => 'Loose', 'status' => Task::STATUS_READY_FOR_PLANNING, 'position' => 0]);
    }

    public function test_soft_deleting_a_task_hides_it_from_default_queries(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        $t = $this->task($d, ['title' => 'Disappearing']);

        $t->delete();

        $this->assertSoftDeleted('tasks', ['id' => $t->id]);
        $this->assertNull(Task::find($t->id));
        $this->assertNotNull(Task::withTrashed()->find($t->id));
    }

    public function test_soft_deleting_a_deliverable_hides_it_from_default_queries(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()), Deliverable::STATUS_BUILDING);

        $d->delete();

        $this->assertSoftDeleted('deliverables', ['id' => $d->id]);
        $this->assertNull(Deliverable::find($d->id));
        $this->assertNotNull(Deliverable::withTrashed()->find($d->id));
    }

    // ── dependency invalidation ───────────────────────────────────────────────

    public function test_replanning_a_dependency_invalidates_approved_dependents(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        $dep = $this->task($d, ['title' => 'Dependency', 'status' => Task::STATUS_READY_FOR_DEV]);
        $dependent = $this->task($d, ['title' => 'Dependent', 'status' => Task::STATUS_READY_FOR_DEV]);
        $dependent->dependencies()->attach($dep->id);

        // The dependency is re-planned (back to plan_review) → the approved dependent
        // is invalidated back to plan_review with a note.
        $dep->update(['status' => Task::STATUS_PLAN_REVIEW]);

        $fresh = $dependent->fresh();
        $this->assertSame(Task::STATUS_PLAN_REVIEW, $fresh->status);
        $this->assertStringContainsString('re-planned', (string) $fresh->rework_notes);
    }

    public function test_done_dependents_are_not_invalidated(): void
    {
        $d = $this->deliverable($this->project(User::factory()->create()));
        $dep = $this->task($d, ['title' => 'Dependency', 'status' => Task::STATUS_READY_FOR_DEV]);
        $doneDependent = $this->task($d, ['title' => 'Shipped', 'status' => Task::STATUS_MERGED]);
        $doneDependent->dependencies()->attach($dep->id);

        $dep->update(['status' => Task::STATUS_PLAN_REVIEW]);

        $this->assertSame(Task::STATUS_MERGED, $doneDependent->fresh()->status); // already shipped — left alone
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
