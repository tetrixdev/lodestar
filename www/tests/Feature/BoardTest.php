<?php

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
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

    /** Create a deliverable with child tasks of the given statuses (one task per status entry). */
    private function deliverableWith(Project $project, array $taskStatuses): Deliverable
    {
        $d = $project->deliverables()->create([
            'title' => 'Deliv '.uniqid(),
            'status' => Deliverable::STATUS_NEW,
            'position' => 0,
        ]);
        foreach ($taskStatuses as $i => $status) {
            $this->makeTask($project, [
                'deliverable' => $d,
                'title' => "T-{$status}-{$i}",
                'status' => $status,
                'position' => $i,
            ]);
        }

        return $d->fresh();
    }

    /** Fetch the board's column => cards map (the derived placement) for the given user. */
    private function placement(User $user): array
    {
        $response = $this->actingAs($user)->get('/board');
        $response->assertOk();

        return $response->viewData('deliverableCardsByPhase');
    }

    private function cardTitlesIn(array $byPhase, string $phase): array
    {
        return collect($byPhase[$phase])
            ->map(fn ($c) => $c['deliverable']->title)
            ->all();
    }

    // ── intra-status reordering (the move endpoint) ──────────────────────────

    public function test_move_reorders_cards_within_a_status(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $a = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);
        $b = $this->makeTask($project, ['title' => 'B', 'status' => 'ready_for_planning', 'position' => 1]);
        $c = $this->makeTask($project, ['title' => 'C', 'status' => 'ready_for_planning', 'position' => 2]);

        // Drag C to the front: new order C, A, B.
        $this->actingAs($user)->patchJson("/tasks/{$c->id}/move", [
            'status' => 'ready_for_planning',
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
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        // move is reorder-only: claiming a different status is rejected.
        $this->actingAs($user)->patchJson("/tasks/{$task->id}/move", [
            'status' => 'developing',
            'order' => [$task->id],
        ])->assertStatus(422);

        $this->assertSame('ready_for_planning', $task->fresh()->status);
    }

    public function test_move_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        $this->actingAs($intruder)
            ->patchJson("/tasks/{$task->id}/move", ['status' => 'ready_for_planning', 'order' => [$task->id]])
            ->assertForbidden();
    }

    public function test_move_ignores_ids_from_other_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $other = $this->project($user);

        $a = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);
        $foreign = $this->makeTask($other, ['title' => 'X', 'status' => 'ready_for_planning', 'position' => 0]);

        $this->actingAs($user)->patchJson("/tasks/{$a->id}/move", [
            'status' => 'ready_for_planning',
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
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        // Backdate the stamp so we can detect a fresh stamp on transition.
        $past = now()->subDays(3);
        DB::table('tasks')->where('id', $task->id)->update(['status_changed_at' => $past]);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'planning'])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('planning', $fresh->status);
        $this->assertTrue($fresh->status_changed_at->greaterThan($past));
    }

    public function test_illegal_transition_is_rejected_with_422(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        // ready_for_planning may only go to planning or cancelled — not straight to merged.
        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'merged'])
            ->assertSessionHasErrors('status');

        $this->assertSame('ready_for_planning', $task->fresh()->status);
    }

    public function test_illegal_transition_returns_422_for_json(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        $this->actingAs($user)
            ->patchJson("/tasks/{$task->id}", ['status' => 'developing'])
            ->assertStatus(422);

        $this->assertSame('ready_for_planning', $task->fresh()->status);
    }

    public function test_cancel_is_a_permanent_archive(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'developing', 'position' => 0]);

        // Any live status may cancel.
        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['status' => 'cancelled'])
            ->assertRedirect();
        $this->assertSame('cancelled', $task->fresh()->status);

        // Cancelled is a permanent archive — it cannot be restored to a live state.
        $this->actingAs($user)
            ->from("/tasks/{$task->id}")
            ->patch("/tasks/{$task->id}", ['status' => 'ready_for_planning'])
            ->assertSessionHasErrors('status');
        $this->assertSame('cancelled', $task->fresh()->status);
    }

    public function test_transition_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        $this->actingAs($intruder)
            ->patch("/tasks/{$task->id}", ['status' => 'planning'])
            ->assertForbidden();

        $this->assertSame('ready_for_planning', $task->fresh()->status);
    }

    // ── creating cards ───────────────────────────────────────────────────────

    public function test_new_card_gets_a_status_changed_at_stamp(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $task = $this->makeTask($project, ['title' => 'A', 'status' => 'ready_for_planning', 'position' => 0]);

        $this->assertNotNull($task->fresh()->status_changed_at);
    }

    // ── deliverable-only board placement (W4) ────────────────────────────────

    public function test_zero_task_deliverable_appears_only_in_backlog(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $d = $project->deliverables()->create([
            'title' => 'Empty deliverable',
            'status' => Deliverable::STATUS_NEW,
            'position' => 0,
        ]);

        $byPhase = $this->placement($user);

        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'backlog'));
        foreach (['plan', 'build', 'review', 'ship'] as $col) {
            $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, $col));
        }
    }

    public function test_ready_for_planning_task_places_deliverable_in_plan_not_backlog(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $d = $this->deliverableWith($project, [Task::STATUS_READY_FOR_PLANNING]);

        $byPhase = $this->placement($user);

        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'plan'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'backlog'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'build'));
    }

    public function test_deliverable_with_mixed_planning_and_build_tasks_appears_in_both(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $d = $this->deliverableWith($project, [
            Task::STATUS_PLANNING,
            Task::STATUS_DEVELOPING,
        ]);

        $byPhase = $this->placement($user);

        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'plan'));
        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'build'));

        // The PLAN card carries only the planning task; the BUILD card only the build task.
        $planCard = collect($byPhase['plan'])->firstWhere('deliverable.id', $d->id);
        $buildCard = collect($byPhase['build'])->firstWhere('deliverable.id', $d->id);
        $this->assertSame([Task::STATUS_PLANNING], $planCard['tasks']->pluck('status')->all());
        $this->assertSame([Task::STATUS_DEVELOPING], $buildCard['tasks']->pluck('status')->all());
    }

    public function test_merged_tasks_stay_visible_in_build_until_all_merged(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        // One merged + one still developing → not all merged, so the deliverable
        // stays task-derived and the merged task remains visible in BUILD.
        $d = $this->deliverableWith($project, [
            Task::STATUS_MERGED,
            Task::STATUS_DEVELOPING,
        ]);

        $byPhase = $this->placement($user);

        $buildCard = collect($byPhase['build'])->firstWhere('deliverable.id', $d->id);
        $this->assertNotNull($buildCard, 'deliverable should be in BUILD');
        $this->assertEqualsCanonicalizing(
            [Task::STATUS_MERGED, Task::STATUS_DEVELOPING],
            $buildCard['tasks']->pluck('status')->all(),
        );

        // Not an own-card review/ship column yet.
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'review'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'ship'));
    }

    public function test_merged_tasks_render_green_in_build(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->deliverableWith($project, [
            Task::STATUS_MERGED,
            Task::STATUS_DEVELOPING,
        ]);

        $this->actingAs($user)->get('/board')
            ->assertOk()
            ->assertSee('text-emerald-600', false);
    }

    public function test_all_tasks_merged_deliverable_leaves_build_for_its_review_or_ship_column(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        // All tasks merged → syncStatus derives ready_for_ai_review (review column),
        // and the deliverable leaves the task-derived BUILD column entirely.
        $d = $this->deliverableWith($project, [
            Task::STATUS_MERGED,
            Task::STATUS_MERGED,
        ]);

        $this->assertSame(Deliverable::STATUS_READY_FOR_AI_REVIEW, $d->status);

        $byPhase = $this->placement($user);

        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'review'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'build'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'backlog'));
    }

    public function test_all_merged_then_advanced_to_ship_shows_in_ship_column(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $d = $this->deliverableWith($project, [Task::STATUS_MERGED]);
        // Drive its own status into the ship phase.
        $d->forceFill(['status' => Deliverable::STATUS_APPROVED])->save();

        $byPhase = $this->placement($user);

        $this->assertContains($d->title, $this->cardTitlesIn($byPhase, 'ship'));
        $this->assertNotContains($d->title, $this->cardTitlesIn($byPhase, 'build'));
    }
}
