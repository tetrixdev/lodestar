<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AdvanceDeliverableTool;
use App\Mcp\Tools\ClaimWorkTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\UpsertDeliverableTool;
use App\Models\Deliverable;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpDeliverableToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_task_returns_contents_and_reports_missing(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'Readable', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 0]);

        LodestarServer::actingAs($user)
            ->tool(GetTaskTool::class, ['task_ids' => [$task->id, 99999]])
            ->assertOk()
            ->assertSee('Readable')
            ->assertSee('99999'); // reported in missing
    }

    public function test_upsert_deliverable_creates_and_raises_questions(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(UpsertDeliverableTool::class, [
                'project' => 'p',
                'title' => 'Ship v1',
                'plan' => 'do the things',
                'plan_summary' => 'things',
                'questions' => ['Which auth?', 'Which db?'],
            ])
            ->assertOk()
            ->assertSee('"status":"plan_review"') // a plan defaults into plan_review
            ->assertSee('"open_questions":2');

        $this->assertDatabaseHas('deliverables', ['project_id' => $project->id, 'title' => 'Ship v1', 'status' => 'plan_review']);
    }

    public function test_advance_deliverable_enforces_question_gate_then_stamps_branch(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_PLAN_REVIEW]);
        $q = $d->questions()->create(['question' => 'Which?', 'position' => 0]);

        // Blocked by the unanswered question.
        LodestarServer::actingAs($user)
            ->tool(AdvanceDeliverableTool::class, ['deliverable_id' => $d->id, 'to' => Deliverable::STATUS_BUILDING])
            ->assertHasErrors();
        $this->assertSame(Deliverable::STATUS_PLAN_REVIEW, $d->fresh()->status);

        $q->update(['answer' => 'this']);
        LodestarServer::actingAs($user)
            ->tool(AdvanceDeliverableTool::class, ['deliverable_id' => $d->id, 'to' => Deliverable::STATUS_BUILDING])
            ->assertOk();

        $fresh = $d->fresh();
        $this->assertSame(Deliverable::STATUS_BUILDING, $fresh->status);
        $this->assertNotNull($fresh->branch);
    }

    public function test_advance_deliverable_requires_all_tasks_done_before_ai_review(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Open child', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);

        LodestarServer::actingAs($user)
            ->tool(AdvanceDeliverableTool::class, ['deliverable_id' => $d->id, 'to' => Deliverable::STATUS_READY_FOR_AI_REVIEW])
            ->assertHasErrors();
        $this->assertSame(Deliverable::STATUS_BUILDING, $d->fresh()->status);
    }

    public function test_claim_work_takes_a_standalone_task(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'Solo', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 0]);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['agent_id' => 'loop'])
            ->assertOk()
            ->assertSee('"type":"task"');

        $this->assertSame(Task::STATUS_DEVELOPING, $task->fresh()->status);
    }

    public function test_claim_work_only_hands_out_child_task_when_deliverable_is_building(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        // Deliverable NOT yet building → its child is not claimable.
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_PLAN_REVIEW]);
        $child = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Child', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 0]);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('No work available');
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $child->fresh()->status);

        // Move the deliverable to building → the child becomes claimable.
        $d->update(['status' => Deliverable::STATUS_BUILDING]);
        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('"type":"task"');
        $this->assertSame(Task::STATUS_DEVELOPING, $child->fresh()->status);
    }

    public function test_claim_work_skips_a_blocked_child(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $blocker = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Blocker', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);
        $blocked = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Blocked', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 1]);
        $blocked->dependencies()->attach($blocker->id);

        // The only ready child is blocked → nothing to claim.
        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('No work available');
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $blocked->fresh()->status);
    }

    public function test_claim_work_takes_a_deliverable_for_planning(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_READY_FOR_PLANNING]);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'deliverable'])
            ->assertOk()
            ->assertSee('"type":"deliverable"')
            ->assertSee('"phase":"plan"');

        $this->assertSame(Deliverable::STATUS_PLANNING, $d->fresh()->status);
    }
}
