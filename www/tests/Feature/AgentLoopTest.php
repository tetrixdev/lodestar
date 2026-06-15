<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\GetSkillTool;
use App\Models\Skill;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\SystemSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_skill_is_seeded_and_resolvable_by_get_skill(): void
    {
        $this->seed(SystemSkillSeeder::class);
        $user = User::factory()->create();

        $this->assertNotNull(Skill::slotFor(Skill::SCOPE_SYSTEM, null, 'work'));

        // It's a named skill (not a phase) — get_skill(key:'work') returns it.
        LodestarServer::actingAs($user)
            ->tool(GetSkillTool::class, ['key' => 'work'])
            ->assertOk()
            ->assertSee('the loop')
            ->assertSee('claim_task');
    }

    public function test_project_page_shows_the_loop_prompt(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Run the work loop')
            ->assertSee('Copy loop prompt');
    }

    public function test_heartbeat_shows_agent_working_when_a_task_is_in_progress(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        // No working task → idle.
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('No agent');

        // A card in a working (*-ing) state → the heartbeat lights up.
        $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_DEVELOPING]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Agent working');
    }

    public function test_heartbeat_snapshot_ignores_other_users_tasks(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $stranger->projects()->create(['name' => 'X', 'slug' => 'x'])
            ->tasks()->create(['title' => 'theirs', 'status' => Task::STATUS_DEVELOPING]);

        $this->assertSame(0, Task::agentSnapshot($me)['working']);
    }
}
