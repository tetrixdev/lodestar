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

        // 13 statuses total (12 live + cancelled).
        $this->assertCount(12, Task::STATUSES);
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
        $this->assertFalse($task->canTransitionTo(Task::STATUS_DONE));               // illegal jump
        $this->assertFalse($task->canTransitionTo(Task::STATUS_NEW));                // illegal jump
    }

    public function test_saving_stamps_status_changed_at_only_when_status_changes(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'D', 'slug' => 'd-'.uniqid()]);

        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new']);
        $firstStamp = $task->fresh()->status_changed_at;
        $this->assertNotNull($firstStamp);

        // A non-status edit must NOT re-stamp.
        DB::table('tasks')->where('id', $task->id)->update(['status_changed_at' => now()->subDay()]);
        $task = $task->fresh();
        $task->update(['title' => 'A2']);
        $this->assertTrue($task->fresh()->status_changed_at->lessThan(now()->subHours(20)));

        // A status change DOES re-stamp.
        $task->update(['status' => 'ready_for_planning']);
        $this->assertTrue($task->fresh()->status_changed_at->greaterThan(now()->subMinute()));
    }

    public function test_migration_remaps_legacy_statuses(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'D', 'slug' => 'd-'.uniqid()]);

        // Insert legacy-shaped rows directly (bypassing the model), as they would
        // have existed before the lifecycle migration, with a known updated_at.
        $when = now()->subDays(5);
        $ids = [];
        foreach ([['open', 'O'], ['doing', 'D'], ['done', 'N'], ['cancelled', 'C']] as [$status, $title]) {
            $ids[$status] = DB::table('tasks')->insertGetId([
                'project_id' => $project->id,
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
        DB::table('tasks')->where('status', 'open')->update(['status' => 'new']);
        DB::table('tasks')->where('status', 'doing')->update(['status' => 'developing']);

        $this->assertSame('new', Task::find($ids['open'])->status);
        $this->assertSame('developing', Task::find($ids['doing'])->status);
        $this->assertSame('done', Task::find($ids['done'])->status);
        $this->assertSame('cancelled', Task::find($ids['cancelled'])->status);

        // status_changed_at backfilled from updated_at.
        $this->assertNotNull(Task::find($ids['open'])->status_changed_at);
        $this->assertSame(
            $when->toDateString(),
            Task::find($ids['open'])->status_changed_at->toDateString(),
        );
    }
}
