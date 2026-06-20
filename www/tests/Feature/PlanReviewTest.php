<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanReviewTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $owner, string $status = 'plan_review'): Task
    {
        $project = $owner->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);

        return $project->tasks()->create([
            'title' => 'T',
            'status' => $status,
            'position' => 0,
            'plan' => 'Do the thing.',
            'plan_summary' => 'Thing.',
        ]);
    }

    private function section(Task $task, array $attrs = [])
    {
        return $task->planReviewSections()->create(array_merge([
            'position' => 0,
            'title' => 'Data model',
            'status' => 'open',
        ], $attrs));
    }

    // ── Atomic self-assignment ───────────────────────────────────────────────

    public function test_assign_claims_an_unassigned_plan_review(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);

        $this->actingAs($owner)
            ->post(route('plan-review.assign', $task))
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame($owner->id, $task->fresh()->plan_reviewer_id);
    }

    public function test_claim_for_is_atomic_second_claim_noops(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        $task = $this->task($owner);

        $this->assertTrue($task->claimPlanReviewFor($owner->id));
        $this->assertFalse($task->fresh()->claimPlanReviewFor($second->id));
        $this->assertSame($owner->id, $task->fresh()->plan_reviewer_id);
    }

    public function test_release_only_succeeds_for_the_holder(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $task->claimPlanReviewFor($owner->id);

        $intruder = User::factory()->create();
        $this->assertFalse($task->fresh()->releasePlanReviewFor($intruder->id));
        $this->assertSame($owner->id, $task->fresh()->plan_reviewer_id);

        $this->actingAs($owner)
            ->post(route('plan-review.unassign', $task))
            ->assertRedirect(route('tasks.show', $task));
        $this->assertNull($task->fresh()->plan_reviewer_id);
    }

    // ── Sign-off gate ────────────────────────────────────────────────────────

    public function test_update_section_is_403_when_not_holder(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $section = $this->section($task);

        $this->actingAs($owner)
            ->patchJson(route('plan-review.sections.update', [$task, $section]), ['status' => 'signed_off'])
            ->assertForbidden();

        $this->assertSame('open', $section->fresh()->status);
    }

    public function test_update_section_succeeds_for_the_holder(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $section = $this->section($task);
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->patchJson(route('plan-review.sections.update', [$task, $section]),
                ['status' => 'signed_off', 'decision' => 'approved', 'note' => 'looks good'])
            ->assertOk()
            ->assertJson(['ok' => true, 'signed_off' => 1, 'total' => 1]);

        $section = $section->fresh();
        $this->assertSame('signed_off', $section->status);
        $this->assertSame('approved', $section->decision);
        $this->assertSame('looks good', $section->note);
    }

    public function test_decision_can_be_cleared_back_to_undecided(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $section = $this->section($task, ['decision' => 'approved']);
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->patchJson(route('plan-review.sections.update', [$task, $section]), ['decision' => null])
            ->assertOk();

        $this->assertNull($section->fresh()->decision);
    }

    // ── Conclude drives the task ─────────────────────────────────────────────

    public function test_conclude_is_refused_while_a_section_is_undecided(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['decision' => 'approved']);
        $this->section($task, ['position' => 1, 'title' => 'Migration']); // undecided
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->post(route('plan-review.conclude', $task))
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame('plan_review', $task->fresh()->status);
    }

    public function test_conclude_is_gated_on_holding_the_plan_review(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['decision' => 'approved']);

        $this->actingAs($owner)
            ->post(route('plan-review.conclude', $task))
            ->assertForbidden();
    }

    public function test_conclude_all_approved_advances_to_ready_for_dev(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['decision' => 'approved']);
        $this->section($task, ['position' => 1, 'title' => 'Migration', 'decision' => 'approved']);
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->post(route('plan-review.conclude', $task))
            ->assertRedirect(route('tasks.show', $task));

        $task = $task->fresh();
        $this->assertSame('ready_for_dev', $task->status);
        $this->assertNull($task->plan_reviewer_id);
        $this->assertNull($task->plan_rework_notes);
    }

    public function test_conclude_changes_requested_sends_back_to_planning_with_notes(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['decision' => 'approved']);
        $this->section($task, [
            'position' => 1, 'title' => 'Migration',
            'decision' => 'changes_requested', 'note' => 'Drop the redundant index.',
        ]);
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->post(route('plan-review.conclude', $task))
            ->assertRedirect(route('tasks.show', $task));

        $task = $task->fresh();
        $this->assertSame('ready_for_planning', $task->status);
        $this->assertNull($task->plan_reviewer_id);
        $this->assertStringContainsString('Migration', $task->plan_rework_notes);
        $this->assertStringContainsString('Drop the redundant index.', $task->plan_rework_notes);
    }

    public function test_conclude_is_one_shot_and_will_not_re_drive_a_moved_card(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner, 'ready_for_dev'); // already past the gate
        $this->section($task, ['decision' => 'changes_requested', 'note' => 'x']);
        $task->claimPlanReviewFor($owner->id);

        $this->actingAs($owner)
            ->post(route('plan-review.conclude', $task))
            ->assertRedirect(route('tasks.show', $task));

        // Untouched — not re-driven back to planning.
        $this->assertSame('ready_for_dev', $task->fresh()->status);
        $this->assertNull($task->fresh()->plan_rework_notes);
    }

    // ── Plan decision summary ────────────────────────────────────────────────

    public function test_plan_decision_summary_verdict(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['decision' => 'approved']);
        $this->section($task, ['position' => 1, 'decision' => 'changes_requested']);

        $summary = $task->fresh()->planDecisionSummary();
        $this->assertTrue($summary['all_decided']);
        $this->assertSame('changes_requested', $summary['verdict']);
    }

    // ── Page rendering ───────────────────────────────────────────────────────

    public function test_task_page_renders_the_plan_walkthrough_at_the_gate(): void
    {
        $owner = User::factory()->create();
        $task = $this->task($owner);
        $this->section($task, ['title' => 'Data model section']);

        $this->actingAs($owner)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Plan review')
            ->assertSee('Data model section');
    }
}
