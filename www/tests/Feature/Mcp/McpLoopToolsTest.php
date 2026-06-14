<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AdvanceTaskTool;
use App\Mcp\Tools\ClaimTaskTool;
use App\Mcp\Tools\GetSkillTool;
use App\Mcp\Tools\ReportTool;
use App\Models\Skill;
use App\Models\SkillBinding;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\SystemSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpLoopToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_flips_ready_to_working_and_stamps_holder(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_READY_FOR_DEV]);

        LodestarServer::actingAs($user)
            ->tool(ClaimTaskTool::class, ['agent_id' => 'laptop'])
            ->assertOk()
            ->assertSee('"claimed":true');

        $task->refresh();
        $this->assertSame(Task::STATUS_DEVELOPING, $task->status);
        $this->assertSame('laptop', $task->claimed_by);
        $this->assertNotNull($task->claimed_at);
    }

    public function test_claim_cannot_grab_a_human_gate(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $gate = $project->tasks()->create(['title' => 'G', 'status' => Task::STATUS_PLAN_REVIEW]);

        LodestarServer::actingAs($user)
            ->tool(ClaimTaskTool::class, [])
            ->assertOk()
            ->assertSee('No task available');

        $this->assertSame(Task::STATUS_PLAN_REVIEW, $gate->fresh()->status);
    }

    public function test_a_second_claim_does_not_re_grab_the_only_card(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_READY_FOR_PLANNING]);

        LodestarServer::actingAs($user)->tool(ClaimTaskTool::class, [])->assertOk()->assertSee('"claimed":true');
        LodestarServer::actingAs($user)->tool(ClaimTaskTool::class, [])->assertOk()->assertSee('No task available');
    }

    public function test_claim_by_task_id_takes_that_specific_card(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        // Two queued cards; the first by position is A, but we target B.
        $a = $project->tasks()->create(['title' => 'A', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 1]);
        $b = $project->tasks()->create(['title' => 'B', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 2]);

        LodestarServer::actingAs($user)
            ->tool(ClaimTaskTool::class, ['task_id' => $b->id])
            ->assertOk()->assertSee('"id":'.$b->id);

        $this->assertSame(Task::STATUS_DEVELOPING, $b->fresh()->status);
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $a->fresh()->status); // untouched
    }

    public function test_claim_by_task_id_rejects_a_non_claimable_card(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_NEW]);

        LodestarServer::actingAs($user)
            ->tool(ClaimTaskTool::class, ['task_id' => $task->id])
            ->assertHasErrors();
        $this->assertSame(Task::STATUS_NEW, $task->fresh()->status);
    }

    public function test_phase_filter_only_claims_matching_queue(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->tasks()->create(['title' => 'D', 'status' => Task::STATUS_READY_FOR_DEV]);

        // Asking for the plan phase finds nothing (the only card is a dev card).
        LodestarServer::actingAs($user)
            ->tool(ClaimTaskTool::class, ['phase' => 'plan'])
            ->assertOk()->assertSee('No task available');
    }

    public function test_advance_accepts_a_legal_move_clears_claim_and_rejects_illegal(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create([
            'title' => 'T', 'status' => Task::STATUS_DEVELOPING,
            'claimed_by' => 'laptop', 'claimed_at' => now(),
        ]);

        // Illegal jump rejected, task untouched.
        LodestarServer::actingAs($user)
            ->tool(AdvanceTaskTool::class, ['task_id' => $task->id, 'to' => Task::STATUS_DONE])
            ->assertHasErrors();
        $this->assertSame(Task::STATUS_DEVELOPING, $task->fresh()->status);

        // Legal forward move accepted; claim cleared on leaving the working state.
        LodestarServer::actingAs($user)
            ->tool(AdvanceTaskTool::class, ['task_id' => $task->id, 'to' => Task::STATUS_READY_FOR_AI_REVIEW])
            ->assertOk();

        $task->refresh();
        $this->assertSame(Task::STATUS_READY_FOR_AI_REVIEW, $task->status);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->claimed_at);
    }

    public function test_advance_refuses_to_restore_a_cancelled_card(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_CANCELLED]);

        LodestarServer::actingAs($user)
            ->tool(AdvanceTaskTool::class, ['task_id' => $task->id, 'to' => Task::STATUS_NEW])
            ->assertHasErrors();

        $this->assertSame(Task::STATUS_CANCELLED, $task->fresh()->status);
    }

    public function test_report_logs_a_work_session_on_the_tasks_project(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_DEVELOPING]);

        LodestarServer::actingAs($user)
            ->tool(ReportTool::class, ['task_id' => $task->id, 'title' => 'Built the thing'])
            ->assertOk();

        $this->assertSame('Built the thing', $project->workSessions()->sole()->title);
    }

    public function test_get_skill_returns_the_main_bootstrap_skill_without_a_task(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(GetSkillTool::class, ['phase' => 'main'])
            ->assertOk()
            ->assertSee('"kind":"system"')
            ->assertSee('start here');
    }

    public function test_every_phase_has_a_seeded_system_skill(): void
    {
        $this->seed(SystemSkillSeeder::class);

        foreach (Skill::PHASES as $phase) {
            $this->assertNotNull(
                Skill::currentSystem($phase),
                "no system skill seeded for phase {$phase}",
            );
        }
    }

    public function test_get_skill_falls_back_to_system_then_prefers_a_fork(): void
    {
        $this->seed(SystemSkillSeeder::class);

        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_DEVELOPING]);

        // No binding → the current system develop skill.
        LodestarServer::actingAs($user)
            ->tool(GetSkillTool::class, ['task_id' => $task->id])
            ->assertOk()
            ->assertSee('"kind":"system"')
            ->assertSee('Develop a task');

        // Bind a user fork → get_skill now returns the fork.
        $fork = Skill::create([
            'kind' => Skill::KIND_USER, 'key' => 'develop', 'version' => 1,
            'title' => 'My develop', 'body' => 'custom prompt', 'user_id' => $user->id, 'source_version' => 1,
        ]);
        SkillBinding::create([
            'user_id' => $user->id, 'project_id' => null, 'phase' => 'develop', 'skill_id' => $fork->id,
        ]);

        LodestarServer::actingAs($user)
            ->tool(GetSkillTool::class, ['task_id' => $task->id])
            ->assertOk()
            ->assertSee('"kind":"user"')
            ->assertSee('custom prompt');
    }
}
