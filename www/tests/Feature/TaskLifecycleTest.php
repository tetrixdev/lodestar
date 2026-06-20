<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_status_has_a_phase_actor_and_label(): void
    {
        $phaseStatuses = collect(Task::PHASES)->flatMap(fn ($p) => $p['statuses'])->all();

        foreach (Task::STATUSES as $status) {
            $this->assertContains($status, $phaseStatuses, "$status missing from a phase");
            $this->assertArrayHasKey($status, Task::ACTORS, "$status missing an actor");
            $this->assertArrayHasKey($status, Task::LABELS, "$status missing a label");
        }

        // 12 statuses total (11 live + cancelled).
        $this->assertCount(11, Task::STATUSES);
        $this->assertArrayHasKey(Task::STATUS_CANCELLED, Task::ACTORS);
    }

    public function test_transition_map_only_references_known_statuses(): void
    {
        $known = array_merge(Task::STATUSES, [Task::STATUS_CANCELLED]);

        foreach (Task::TRANSITIONS as $from => $targets) {
            $this->assertContains($from, $known, "unknown source status $from");
            foreach ($targets as $t) {
                $this->assertContains($t, $known, "unknown target $t from $from");
            }
        }
    }

    public function test_can_transition_to_enforces_the_map(): void
    {
        $task = new Task(['status' => Task::STATUS_HUMAN_REVIEW]);

        $this->assertTrue($task->canTransitionTo(Task::STATUS_APPROVED));            // forward
        $this->assertTrue($task->canTransitionTo(Task::STATUS_READY_FOR_AI_REVIEW)); // back
        $this->assertTrue($task->canTransitionTo(Task::STATUS_READY_FOR_DEV));       // back to dev (rework)
        $this->assertTrue($task->canTransitionTo(Task::STATUS_CANCELLED));           // cancel
        $this->assertFalse($task->canTransitionTo(Task::STATUS_MERGED));             // illegal jump
        $this->assertFalse($task->canTransitionTo(Task::STATUS_PLANNING));           // illegal jump
    }

    public function test_normal_task_at_ai_review_must_pass_through_human_review(): void
    {
        // needs_functional_review defaults true → the human gate stands.
        $task = new Task(['status' => Task::STATUS_AI_REVIEW, 'needs_functional_review' => true]);

        $this->assertContains(Task::STATUS_HUMAN_REVIEW, $task->allowedTransitions());
        $this->assertTrue($task->canTransitionTo(Task::STATUS_HUMAN_REVIEW));
        $this->assertFalse($task->canTransitionTo(Task::STATUS_APPROVED), 'normal task cannot skip human review');
        $this->assertTrue($task->requiresHumanReview());
    }

    public function test_corrective_task_at_ai_review_skips_human_review_to_approved(): void
    {
        // A deliverable-review fix (needs_functional_review = false) flows
        // ai_review ⇒ approved, bypassing the per-task human gate. AI review itself
        // is still mandatory (developing ⇒ ready_for_ai_review ⇒ ai_review unchanged).
        $task = new Task([
            'status' => Task::STATUS_AI_REVIEW,
            'is_corrective' => true,
            'needs_functional_review' => false,
        ]);

        $this->assertFalse($task->requiresHumanReview());
        $this->assertTrue($task->canTransitionTo(Task::STATUS_APPROVED), 'skip task may go straight to approved');
        $this->assertFalse($task->canTransitionTo(Task::STATUS_HUMAN_REVIEW), 'human gate is bypassed entirely');
        // Forward target is approved (preserves forward · back · cancel ordering).
        $this->assertSame(Task::STATUS_APPROVED, $task->allowedTransitions()[0]);
        // The AI-review step into this state is unaffected.
        $dev = new Task(['status' => Task::STATUS_READY_FOR_AI_REVIEW, 'needs_functional_review' => false]);
        $this->assertTrue($dev->canTransitionTo(Task::STATUS_AI_REVIEW));
    }

    public function test_saving_stamps_status_changed_at_only_when_status_changes(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'D', 'slug' => 'd-'.uniqid()]);

        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning']);
        $firstStamp = $task->fresh()->status_changed_at;
        $this->assertNotNull($firstStamp);

        // A non-status edit must NOT re-stamp.
        DB::table('tasks')->where('id', $task->id)->update(['status_changed_at' => now()->subDay()]);
        $task = $task->fresh();
        $task->update(['title' => 'A2']);
        $this->assertTrue($task->fresh()->status_changed_at->lessThan(now()->subHours(20)));

        // A status change DOES re-stamp.
        $task->update(['status' => 'planning']);
        $this->assertTrue($task->fresh()->status_changed_at->greaterThan(now()->subMinute()));
    }

    public function test_migration_remaps_legacy_statuses(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'D', 'slug' => 'd-'.uniqid()]);
        $deliverable = $project->deliverables()->create(['title' => 'D', 'status' => 'building']);

        // Insert legacy-shaped rows directly (bypassing the model), as they would
        // have existed before the lifecycle migration, with a known updated_at.
        // Every task belongs to a deliverable (required FK); sub_id is unique per
        // deliverable, so give each a distinct one.
        $when = now()->subDays(5);
        $ids = [];
        $sub = 1;
        foreach ([['open', 'O'], ['doing', 'D'], ['done', 'N'], ['cancelled', 'C']] as [$status, $title]) {
            $ids[$status] = DB::table('tasks')->insertGetId([
                'project_id' => $project->id,
                'deliverable_id' => $deliverable->id,
                'sub_id' => $sub++,
                'title' => $title,
                'status' => $status,
                'position' => 0,
                'status_changed_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }

        // Re-run the lifecycle migration's data transform (the column already
        // exists from RefreshDatabase; this mirrors the up() body exactly).
        DB::table('tasks')->whereNull('status_changed_at')->update(['status_changed_at' => DB::raw('updated_at')]);
        DB::table('tasks')->where('status', 'open')->update(['status' => 'ready_for_planning']);
        DB::table('tasks')->where('status', 'doing')->update(['status' => 'developing']);
        DB::table('tasks')->where('status', 'done')->update(['status' => 'merged']);

        $this->assertSame('ready_for_planning', Task::find($ids['open'])->status);
        $this->assertSame('developing', Task::find($ids['doing'])->status);
        $this->assertSame('merged', Task::find($ids['done'])->status);
        $this->assertSame('cancelled', Task::find($ids['cancelled'])->status);

        // status_changed_at backfilled from updated_at.
        $this->assertNotNull(Task::find($ids['open'])->status_changed_at);
        $this->assertSame(
            $when->toDateString(),
            Task::find($ids['open'])->status_changed_at->toDateString(),
        );
    }
}
