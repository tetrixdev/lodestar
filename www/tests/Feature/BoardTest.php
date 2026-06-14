<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // ── intra-status reordering (the move endpoint) ──────────────────────────

    public function test_move_reorders_cards_within_a_status(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $a = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);
        $b = $project->tasks()->create(['title' => 'B', 'status' => 'new', 'position' => 1]);
        $c = $project->tasks()->create(['title' => 'C', 'status' => 'new', 'position' => 2]);

        // Drag C to the front: new order C, A, B.
        $this->actingAs($user)->patchJson("/tasks/{$c->id}/move", [
            'status' => 'new',
            'order' => [$c->id, $a->id, $b->id],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_move_rejects_a_status_the_card_is_not_in(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        // move is reorder-only: claiming a different status is rejected.
        $this->actingAs($user)->patchJson("/tasks/{$task->id}/move", [
            'status' => 'developing',
            'order' => [$task->id],
        ])->assertStatus(422);

        $this->assertSame('new', $task->fresh()->status);
    }

    public function test_move_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($intruder)
            ->patchJson("/tasks/{$task->id}/move", ['status' => 'new', 'order' => [$task->id]])
            ->assertForbidden();
    }

    public function test_move_ignores_ids_from_other_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $other = $this->project($user);

        $a = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);
        $foreign = $other->tasks()->create(['title' => 'X', 'status' => 'new', 'position' => 0]);

        $this->actingAs($user)->patchJson("/tasks/{$a->id}/move", [
            'status' => 'new',
            'order' => [$foreign->id, $a->id],
        ])->assertOk();

        // The foreign card keeps its own position; only owned ids are reordered.
        $this->assertSame(0, $foreign->fresh()->position);
        $this->assertSame(0, $a->fresh()->position);
    }

    // ── lifecycle transitions (the update endpoint) ──────────────────────────

    public function test_legal_transition_succeeds_and_stamps_status_changed_at(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        // Backdate the stamp so we can detect a fresh stamp on transition.
        $past = now()->subDays(3);
        DB::table('tasks')->where('id', $task->id)->update(['status_changed_at' => $past]);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'ready_for_planning'])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('ready_for_planning', $fresh->status);
        $this->assertTrue($fresh->status_changed_at->greaterThan($past));
    }

    public function test_illegal_transition_is_rejected_with_422(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        // new may only go to ready_for_planning or cancelled — not straight to done.
        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'done'])
            ->assertSessionHasErrors('status');

        $this->assertSame('new', $task->fresh()->status);
    }

    public function test_illegal_transition_returns_422_for_json(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($user)
            ->patchJson("/tasks/{$task->id}", ['status' => 'developing'])
            ->assertStatus(422);

        $this->assertSame('new', $task->fresh()->status);
    }

    public function test_cancel_and_restore_round_trip(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'developing', 'position' => 0]);

        // Any live status may cancel.
        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'cancelled'])
            ->assertRedirect();
        $this->assertSame('cancelled', $task->fresh()->status);

        // Restore brings it back to new.
        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'new'])
            ->assertRedirect();
        $this->assertSame('new', $task->fresh()->status);
    }

    public function test_transition_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($intruder)
            ->patch("/tasks/{$task->id}", ['status' => 'ready_for_planning'])
            ->assertForbidden();

        $this->assertSame('new', $task->fresh()->status);
    }

    // ── creating cards ───────────────────────────────────────────────────────

    public function test_store_defaults_to_new(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post("/projects/{$project->id}/tasks", ['title' => 'Fresh'])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title' => 'Fresh',
            'status' => 'new',
        ]);
    }

    public function test_store_can_target_a_phase_status(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post("/projects/{$project->id}/tasks", ['title' => 'Q', 'status' => 'ready_for_dev'])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title' => 'Q',
            'status' => 'ready_for_dev',
        ]);
    }

    public function test_new_card_gets_a_status_changed_at_stamp(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->assertNotNull($task->fresh()->status_changed_at);
    }
}
