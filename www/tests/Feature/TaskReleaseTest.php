<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_returns_a_working_card_to_its_queue_and_clears_the_claim(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create([
            'title' => 'T', 'status' => Task::STATUS_DEVELOPING,
            'claimed_by' => 'laptop', 'claimed_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('tasks.release', $task))->assertRedirect();

        $task->refresh();
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $task->status);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->claimed_at);
    }

    public function test_release_rejects_a_non_working_card(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_READY_FOR_PLANNING]);

        $this->actingAs($user)->patch(route('tasks.release', $task))->assertStatus(422);
        $this->assertSame(Task::STATUS_READY_FOR_PLANNING, $task->fresh()->status);
    }

    public function test_release_requires_ownership(): void
    {
        $owner = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_DEVELOPING]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tasks.release', $task))
            ->assertForbidden();

        $this->assertSame(Task::STATUS_DEVELOPING, $task->fresh()->status);
    }
}
