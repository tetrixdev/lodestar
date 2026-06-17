<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReapStalledTasksTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $status, ?int $minutesAgo): Task
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
        $task = $project->tasks()->create([
            'title' => 'T', 'status' => $status, 'position' => 0,
            'claimed_by' => 'loop', 'claimed_at' => now(),
        ]);

        // The saving hook stamps status_changed_at to now(); backdate it directly.
        if ($minutesAgo !== null) {
            Task::whereKey($task->id)->update(['status_changed_at' => now()->subMinutes($minutesAgo)]);
        }

        return $task->fresh();
    }

    public function test_it_requeues_a_working_task_past_the_lease(): void
    {
        $task = $this->task(Task::STATUS_DEVELOPING, minutesAgo: 120);

        $this->artisan('lodestar:reap-stalled-tasks', ['--minutes' => 60])->assertOk();

        $task->refresh();
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $task->status, 'developing → ready_for_dev');
        $this->assertNull($task->claimed_by, 'claim is released');
        $this->assertNull($task->claimed_at);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'type' => 'worker_reaped']);
    }

    public function test_it_maps_every_working_state_back_to_its_queue(): void
    {
        $cases = [
            Task::STATUS_PLANNING => Task::STATUS_READY_FOR_PLANNING,
            Task::STATUS_DEVELOPING => Task::STATUS_READY_FOR_DEV,
            Task::STATUS_AI_REVIEW => Task::STATUS_READY_FOR_AI_REVIEW,
            Task::STATUS_MERGE_DEPLOY => Task::STATUS_APPROVED,
        ];

        foreach ($cases as $working => $queue) {
            $task = $this->task($working, minutesAgo: 120);
            $this->artisan('lodestar:reap-stalled-tasks', ['--minutes' => 60])->assertOk();
            $this->assertSame($queue, $task->fresh()->status, "{$working} → {$queue}");
        }
    }

    public function test_it_leaves_fresh_and_non_working_tasks_alone(): void
    {
        $fresh = $this->task(Task::STATUS_DEVELOPING, minutesAgo: 5);   // within lease
        $queued = $this->task(Task::STATUS_READY_FOR_DEV, minutesAgo: 120); // not a working state

        $this->artisan('lodestar:reap-stalled-tasks', ['--minutes' => 60])->assertOk();

        $this->assertSame(Task::STATUS_DEVELOPING, $fresh->fresh()->status);
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $queued->fresh()->status);
    }
}
