<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\GetPlaybookTool;
use App\Models\Playbook;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_playbook_is_seeded_and_resolvable_by_get_playbook(): void
    {
        $this->seed(SystemPlaybookSeeder::class);
        $user = User::factory()->create();

        $this->assertNotNull(Playbook::slotFor(Playbook::SCOPE_SYSTEM, null, 'work'));

        // It's a named playbook (not a phase) — get_playbook(key:'work') returns it.
        LodestarServer::actingAs($user)
            ->tool(GetPlaybookTool::class, ['key' => 'work'])
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

    public function test_heartbeat_labels_the_loop_when_claimed_by_loop(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $project->tasks()->create([
            'title' => 'T', 'status' => Task::STATUS_DEVELOPING, 'claimed_by' => 'loop',
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Loop running');
    }

    public function test_agents_page_lists_working_agents_and_their_tasks(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);
        $project->tasks()->create(['title' => 'Build it', 'status' => Task::STATUS_DEVELOPING, 'claimed_by' => 'loop']);

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertSee('loop')
            ->assertSee('Build it')
            ->assertSee('Rocket');
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
