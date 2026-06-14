<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return $user->projects()->create([
            'name' => 'Demo',
            'slug' => 'demo-'.uniqid(),
        ]);
    }

    public function test_move_reorders_and_restatuses_cards(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $a = $project->tasks()->create(['title' => 'A', 'status' => 'open', 'position' => 0]);
        $b = $project->tasks()->create(['title' => 'B', 'status' => 'open', 'position' => 1]);
        $moved = $project->tasks()->create(['title' => 'M', 'status' => 'doing', 'position' => 0]);

        // Drag "M" into the open column, dropped between A and B.
        $response = $this->actingAs($user)->patchJson("/tasks/{$moved->id}/move", [
            'status' => 'open',
            'order' => [$a->id, $moved->id, $b->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('open', $moved->fresh()->status);
        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $moved->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_move_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'open', 'position' => 0]);

        $this->actingAs($intruder)
            ->patchJson("/tasks/{$task->id}/move", ['status' => 'doing', 'order' => [$task->id]])
            ->assertForbidden();

        $this->assertSame('open', $task->fresh()->status);
    }

    public function test_move_ignores_ids_from_other_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $other = $this->project($user);

        $task = $project->tasks()->create(['title' => 'A', 'status' => 'open', 'position' => 0]);
        $foreign = $other->tasks()->create(['title' => 'X', 'status' => 'open', 'position' => 0]);

        $this->actingAs($user)->patchJson("/tasks/{$task->id}/move", [
            'status' => 'doing',
            'order' => [$foreign->id, $task->id],
        ])->assertOk();

        // The foreign card must not be dragged into this project's column.
        $this->assertSame('open', $foreign->fresh()->status);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame(0, $task->fresh()->position);
    }

    public function test_restore_brings_an_archived_card_back_to_open(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'cancelled', 'position' => 0]);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'open'])
            ->assertRedirect();

        $this->assertSame('open', $task->fresh()->status);
    }

    public function test_store_can_add_to_a_named_column(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post("/projects/{$project->id}/tasks", ['title' => 'New', 'status' => 'doing'])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title' => 'New',
            'status' => 'doing',
        ]);
    }
}
