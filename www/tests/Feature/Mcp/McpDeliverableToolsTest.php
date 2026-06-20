<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AdvanceDeliverableTool;
use App\Mcp\Tools\ClaimWorkTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\UpsertDeliverableTool;
use App\Mcp\Tools\UpsertTaskTool;
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

    public function test_child_task_is_claimable_once_its_plan_is_approved(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_NEW]);

        // Unapproved child (in plan_review) → not claimable; deliverable derives to planning.
        $child = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Child', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 0]);
        $this->assertSame(Deliverable::STATUS_PLANNING, $d->fresh()->status);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('No work available');

        // Approve its plan (plan_review → ready_for_dev) → claimable, even though it
        // was the only task; deliverable derives to building.
        $child->update(['status' => Task::STATUS_READY_FOR_DEV]);
        $this->assertSame(Deliverable::STATUS_BUILDING, $d->fresh()->status);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('"type":"task"');
        $this->assertSame(Task::STATUS_DEVELOPING, $child->fresh()->status);
    }

    public function test_approved_task_starts_while_a_sibling_is_still_in_planning(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_NEW]);
        $approved = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Approved', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 0]);
        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Still planning', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 1]);

        // A sibling is unapproved → deliverable is "planning", but the approved task still starts.
        $this->assertSame(Deliverable::STATUS_PLANNING, $d->fresh()->status);
        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'task'])
            ->assertOk()
            ->assertSee('"id":'.$approved->id);
        $this->assertSame(Task::STATUS_DEVELOPING, $approved->fresh()->status);
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

    public function test_upsert_task_attaches_a_child_and_wires_dependencies(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $first = $d->tasks()->create(['project_id' => $project->id, 'title' => 'First', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 0]);

        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, [
                'deliverable' => $d->id,
                'title' => 'Second',
                'depends_on' => [$first->id],
            ])
            ->assertOk()
            ->assertSee('"status":"new"'); // a bare child starts as new (no plan passed)

        $second = Task::where('title', 'Second')->sole();
        $this->assertSame($d->id, $second->deliverable_id);
        $this->assertNotNull($second->sub_id);
        $this->assertTrue($second->dependencies->contains($first->id));
    }

    public function test_corrective_task_skips_functional_review_and_enters_dev(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);

        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, [
                'deliverable' => $d->id,
                'title' => 'Fix the thing AI review flagged',
                'corrective' => true,
            ])
            ->assertOk()
            ->assertSee('"status":"ready_for_dev"');

        $t = Task::where('title', 'Fix the thing AI review flagged')->sole();
        $this->assertTrue($t->is_corrective);
        $this->assertFalse($t->needs_functional_review);
        $this->assertSame($d->id, $t->deliverable_id);
    }

    public function test_corrective_requires_a_deliverable(): void
    {
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'Orphan fix', 'corrective' => true])
            ->assertHasErrors();
    }

    public function test_claim_work_takes_a_backlog_deliverable_with_a_scope_for_decomposition(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        // A backlog (new) deliverable WITH a scope is claimed for decomposition.
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_NEW, 'body' => 'Build the thing', 'body_summary' => 'thing']);

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'deliverable'])
            ->assertOk()
            ->assertSee('"type":"deliverable"')
            ->assertSee('"phase":"plan"');

        $this->assertSame(Deliverable::STATUS_PLANNING, $d->fresh()->status);
    }

    public function test_claim_work_skips_a_scopeless_backlog_deliverable(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->deliverables()->create(['title' => 'Empty', 'status' => Deliverable::STATUS_NEW]); // no scope yet

        LodestarServer::actingAs($user)
            ->tool(ClaimWorkTool::class, ['type' => 'deliverable'])
            ->assertOk()
            ->assertSee('No work available');
    }
}
