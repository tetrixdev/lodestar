<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AddFindingTool;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewLogicTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return $owner->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);
    }

    private function review(Project $project, string $status = 'draft'): Review
    {
        return $project->reviews()->create(['title' => 'R', 'status' => $status]);
    }

    private function section(Review $review, ?string $decision = null): ReviewSection
    {
        return $review->sections()->create([
            'position' => $review->sections()->max('position') + 1,
            'title' => 'S',
            'mode' => 'direct',
            'status' => 'open',
            'decision' => $decision,
        ]);
    }

    // ── add_finding over MCP ─────────────────────────────────────────────────

    public function test_add_finding_creates_a_finding_on_a_draft_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner));
        $section = $this->section($review);

        LodestarServer::actingAs($owner)->tool(AddFindingTool::class, [
            'section_id' => $section->id,
            'title' => 'Race in claim',
            'detail' => 'Two agents could both win the card; double-pickup.',
            'severity' => 'major',
        ])->assertOk()->assertSee('"finding_count":1');

        $finding = $section->findings()->sole();
        $this->assertSame('Race in claim', $finding->title);
        $this->assertSame('major', $finding->severity);
        $this->assertSame('open', $finding->status);
        $this->assertSame(1, $finding->position);
    }

    public function test_add_finding_defaults_severity_to_minor_and_increments_position(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner));
        $section = $this->section($review);
        $section->findings()->create(['title' => 'first', 'severity' => 'info', 'status' => 'open', 'position' => 1]);

        LodestarServer::actingAs($owner)->tool(AddFindingTool::class, [
            'section_id' => $section->id, 'title' => 'second',
        ])->assertOk();

        $second = $section->findings()->where('title', 'second')->sole();
        $this->assertSame('minor', $second->severity);
        $this->assertSame(2, $second->position);
    }

    public function test_add_finding_is_rejected_on_a_non_draft_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);

        LodestarServer::actingAs($owner)->tool(AddFindingTool::class, [
            'section_id' => $section->id, 'title' => 'too late',
        ])->assertHasErrors();

        $this->assertSame(0, $section->findings()->count());
    }

    public function test_add_finding_cannot_reach_another_users_section(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $review = $this->review($this->project($owner));
        $section = $this->section($review);

        LodestarServer::actingAs($intruder)->tool(AddFindingTool::class, [
            'section_id' => $section->id, 'title' => 'sneaky',
        ])->assertHasErrors();

        $this->assertSame(0, $section->findings()->count());
    }

    // ── finding triage (web) ─────────────────────────────────────────────────

    public function test_finding_triage_persists_for_the_holder(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);
        $finding = $section->findings()->create(['title' => 'f', 'severity' => 'minor', 'status' => 'open', 'position' => 1]);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->patchJson(route('reviews.findings.update', [$review, $section, $finding]), ['status' => 'must_fix'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'must_fix']);

        $this->assertSame('must_fix', $finding->fresh()->status);
    }

    public function test_finding_triage_is_gated_on_holding_the_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);
        $finding = $section->findings()->create(['title' => 'f', 'severity' => 'minor', 'status' => 'open', 'position' => 1]);

        // Not assigned → blocked.
        $this->actingAs($owner)
            ->patchJson(route('reviews.findings.update', [$review, $section, $finding]), ['status' => 'dismissed'])
            ->assertForbidden();

        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_finding_triage_rejects_an_unknown_status(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);
        $finding = $section->findings()->create(['title' => 'f', 'severity' => 'minor', 'status' => 'open', 'position' => 1]);
        $review->claimFor($owner->id);

        // The app renders JSON only for api/* (see bootstrap/app.php); a validation
        // failure on a web route redirects back with the errors flashed.
        $this->actingAs($owner)
            ->patch(route('reviews.findings.update', [$review, $section, $finding]), ['status' => 'bogus'])
            ->assertSessionHasErrors('status');

        $this->assertSame('open', $finding->fresh()->status);
    }

    // ── section decision (web) ───────────────────────────────────────────────

    public function test_section_decision_persists_for_the_holder(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['decision' => 'changes_requested'])
            ->assertOk()
            ->assertJsonPath('decisions.changes_requested', 1);

        $this->assertSame('changes_requested', $section->fresh()->decision);
    }

    public function test_section_decision_is_gated_on_holding_the_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $section = $this->section($review);

        $this->actingAs($owner)
            ->patchJson(route('reviews.sections.update', [$review, $section]), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertNull($section->fresh()->decision);
    }

    // ── conclude ─────────────────────────────────────────────────────────────

    public function test_conclude_is_refused_while_a_section_is_undecided(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $this->section($review, 'approved');
        $this->section($review, null); // undecided
        $task = $review->project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 0]);
        $review->tasks()->attach($task);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->post(route('reviews.conclude', $review))
            ->assertRedirect(route('reviews.show', $review));

        $this->assertNull($review->fresh()->outcome);
        $this->assertSame('in_review', $review->fresh()->status);
        $this->assertSame(Task::STATUS_HUMAN_REVIEW, $task->fresh()->status);
    }

    public function test_conclude_is_gated_on_holding_the_review(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $this->section($review, 'approved');

        $this->actingAs($owner)
            ->post(route('reviews.conclude', $review))
            ->assertForbidden();

        $this->assertNull($review->fresh()->outcome);
    }

    public function test_conclude_all_approved_advances_the_task_to_approved(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $this->section($review, 'approved');
        $this->section($review, 'approved');
        // Carries a stale brief from a prior round — approval should clear it.
        $task = $review->project->tasks()->create([
            'title' => 'T', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 0,
            'rework_notes' => 'leftover from the last round',
        ]);
        $review->tasks()->attach($task);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->post(route('reviews.conclude', $review))
            ->assertRedirect(route('reviews.show', $review));

        $this->assertSame('approved', $review->fresh()->outcome);
        $this->assertSame('done', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->concluded_at, 'conclude stamps the responded time');
        $this->assertSame(Task::STATUS_APPROVED, $task->fresh()->status);
        $this->assertNull($task->fresh()->rework_notes, 'approval clears the stale rework brief');
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'type' => 'review_approved']);
    }

    public function test_conclude_is_one_shot_and_will_not_re_drive_a_task(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');
        $this->section($review, 'approved');
        $task = $review->project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 0]);
        $review->tasks()->attach($task);
        $review->claimFor($owner->id);

        $this->actingAs($owner)->post(route('reviews.conclude', $review));
        $this->assertSame(Task::STATUS_APPROVED, $task->fresh()->status);
        $this->assertSame('approved', $review->fresh()->outcome, 'first conclude should record the outcome');

        // Task legally moved back to human_review; a stale re-conclude must NOT re-drive it.
        $task->refresh();
        $task->update(['status' => Task::STATUS_HUMAN_REVIEW]);
        $this->actingAs($owner)->post(route('reviews.conclude', $review));

        $this->assertSame(Task::STATUS_HUMAN_REVIEW, $task->fresh()->status); // untouched
        $this->assertSame(1, TaskEvent::where('task_id', $task->id)->where('type', 'review_approved')->count());
    }

    public function test_conclude_changes_requested_sends_the_task_back_to_dev_with_rework_notes(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($this->project($owner), 'in_review');

        $s1 = $this->section($review, 'approved');
        $s2 = $this->section($review, 'changes_requested');
        $s2->update(['note' => 'The empty-state copy is wrong.']);
        $s2->findings()->create([
            'title' => 'N+1 on the board', 'detail' => 'Each card refetches its review; 50 queries on a busy board.',
            'severity' => 'major', 'status' => 'must_fix', 'position' => 1,
        ]);
        // a non-must_fix finding must NOT appear in the brief
        $s2->findings()->create([
            'title' => 'nit', 'detail' => 'whatever', 'severity' => 'info', 'status' => 'dismissed', 'position' => 2,
        ]);

        $task = $review->project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 0]);
        $review->tasks()->attach($task);
        $review->claimFor($owner->id);

        $this->actingAs($owner)
            ->post(route('reviews.conclude', $review))
            ->assertRedirect(route('reviews.show', $review));

        $this->assertSame('changes_requested', $review->fresh()->outcome);
        $this->assertSame('done', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->concluded_at, 'conclude stamps the responded time');

        $task = $task->fresh();
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $task->status);
        $this->assertStringContainsString('The empty-state copy is wrong.', $task->rework_notes);
        $this->assertStringContainsString('N+1 on the board', $task->rework_notes);
        $this->assertStringNotContainsString('nit', $task->rework_notes);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'type' => 'review_changes_requested']);
    }
}
