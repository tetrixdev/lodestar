<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\GetDeliverableTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\ListDeliverablesTool;
use App\Mcp\Tools\ListSessionsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Deliverable;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read/navigation MCP tools. Each is read-only and tenant-scoped: the central
 * security assertion is that a sibling user's data NEVER leaks through, exactly as
 * the write tools are scoped (see McpDataToolsTest).
 *
 * The tools answer with Response::json (a JSON string in the tool's text content),
 * so we assert on that text with assertSee / assertDontSee — title strings, the
 * documented field keys, and ordering markers all live in the same payload.
 */
class McpReadToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_tasks_filters_by_project_deliverable_status_and_phase(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d1 = $project->deliverables()->create(['title' => 'D1', 'status' => Deliverable::STATUS_BUILDING]);
        $d2 = $project->deliverables()->create(['title' => 'D2', 'status' => Deliverable::STATUS_BUILDING]);

        $this->makeTask($project, ['deliverable' => $d1, 'title' => 'Dev card', 'status' => Task::STATUS_DEVELOPING]);
        $this->makeTask($project, ['deliverable' => $d1, 'title' => 'Backlog card', 'status' => Task::STATUS_READY_FOR_PLANNING]);
        $this->makeTask($project, ['deliverable' => $d2, 'title' => 'Other deliverable card', 'status' => Task::STATUS_DEVELOPING]);

        // No filter: all three of this project's tasks, with the COMPACT shape (and no body/plan).
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, ['project' => 'p'])
            ->assertOk()
            ->assertSee(['Dev card', 'Backlog card', 'Other deliverable card'])
            ->assertSee(['"sub_id"', '"phase"', '"category"', '"priority"', '"blocked"', '"human_gate"'])
            ->assertDontSee('"body"')
            ->assertDontSee('"plan"');

        // By deliverable.
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, ['deliverable' => $d1->id])
            ->assertOk()
            ->assertSee(['Dev card', 'Backlog card'])
            ->assertDontSee('Other deliverable card');

        // By status (one).
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, ['project' => 'p', 'status' => ['developing']])
            ->assertOk()
            ->assertSee(['Dev card', 'Other deliverable card'])
            ->assertDontSee('Backlog card');

        // By phase column (backlog == ready_for_planning).
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, ['project' => 'p', 'phase' => 'backlog'])
            ->assertOk()
            ->assertSee('Backlog card')
            ->assertDontSee('Dev card');
    }

    public function test_list_tasks_is_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Mine', 'slug' => 'mine']);
        $this->makeTask($project, ['title' => 'My task', 'status' => Task::STATUS_DEVELOPING]);

        $stranger = User::factory()->create();
        $other = $stranger->projects()->create(['name' => 'Theirs', 'slug' => 'theirs']);
        $this->makeTask($other, ['title' => 'Secret sibling task', 'status' => Task::STATUS_DEVELOPING]);

        // Unfiltered: only my task, never the sibling's.
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, [])
            ->assertOk()
            ->assertSee('My task')
            ->assertDontSee('Secret sibling task');

        // Even naming the stranger's project by slug returns nothing (not accessible).
        LodestarServer::actingAs($user)
            ->tool(ListTasksTool::class, ['project' => 'theirs'])
            ->assertOk()
            ->assertDontSee('Secret sibling task');
    }

    public function test_list_deliverables_returns_documented_shape_and_is_scoped(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'Mine deliverable', 'status' => Deliverable::STATUS_BUILDING]);
        $d->questions()->create(['question' => 'Open one?', 'position' => 1]);
        $this->makeTask($project, ['deliverable' => $d, 'title' => 'T', 'status' => Task::STATUS_DEVELOPING]);

        LodestarServer::actingAs($user)
            ->tool(ListDeliverablesTool::class, ['project' => 'p'])
            ->assertOk()
            ->assertSee('Mine deliverable')
            ->assertSee(['"status"', '"phase"', '"base_branch"', '"branch"', '"open_questions"', '"task_counts"'])
            // one open question, one build-phase task tallied.
            ->assertSee('"open_questions":1')
            ->assertSee('"build":1');

        // A sibling's project is not reachable.
        $stranger = User::factory()->create();
        $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        LodestarServer::actingAs($user)
            ->tool(ListDeliverablesTool::class, ['project' => 'x'])
            ->assertHasErrors();
    }

    public function test_get_deliverable_returns_full_detail_and_is_scoped(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create([
            'title' => 'Full deliverable',
            'status' => Deliverable::STATUS_BUILDING,
            'concept' => 'Raw concept text',
            'body' => 'Spec body text',
        ]);
        $d->questions()->create(['question' => 'Still open?', 'position' => 1]);
        $this->makeTask($project, ['deliverable' => $d, 'title' => 'Child task', 'status' => Task::STATUS_DEVELOPING]);

        LodestarServer::actingAs($user)
            ->tool(GetDeliverableTool::class, ['deliverable_id' => $d->id])
            ->assertOk()
            ->assertSee(['Raw concept text', 'Spec body text', 'Still open?', 'Child task'])
            ->assertSee(['"concept"', '"body"', '"open_questions"', '"questions"', '"tasks"', '"reviews"']);

        // A sibling's deliverable is invisible.
        $stranger = User::factory()->create();
        $otherProject = $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        $foreign = $otherProject->deliverables()->create(['title' => 'Foreign', 'status' => Deliverable::STATUS_BUILDING]);

        LodestarServer::actingAs($user)
            ->tool(GetDeliverableTool::class, ['deliverable_id' => $foreign->id])
            ->assertHasErrors();
    }

    public function test_list_sessions_returns_newest_first_and_is_scoped(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $this->makeTask($project, ['title' => 'Linked task', 'status' => Task::STATUS_DEVELOPING]);

        $project->workSessions()->create(['title' => 'Older session', 'slug' => 'older', 'occurred_on' => '2026-01-01']);
        $project->workSessions()->create(['title' => 'Newer session', 'slug' => 'newer', 'occurred_on' => '2026-02-01', 'task_id' => $task->id]);

        // Newest first: "Newer session" must appear before "Older session" in the payload.
        $response = LodestarServer::actingAs($user)
            ->tool(ListSessionsTool::class, ['project' => 'p'])
            ->assertOk()
            ->assertSee(['Newer session', 'Older session', '"occurred_on"', '"task"']);

        // Filter to one task.
        LodestarServer::actingAs($user)
            ->tool(ListSessionsTool::class, ['project' => 'p', 'task' => $task->id])
            ->assertOk()
            ->assertSee('Newer session')
            ->assertDontSee('Older session');

        // A sibling's project is not reachable.
        $stranger = User::factory()->create();
        $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        LodestarServer::actingAs($user)
            ->tool(ListSessionsTool::class, ['project' => 'x'])
            ->assertHasErrors();
    }

    public function test_get_project_returns_overview_and_is_scoped(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Lodestar', 'slug' => 'lodestar',
            'primary_goal' => 'Ship the board', 'description' => 'Control layer',
        ]);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $this->makeTask($project, ['deliverable' => $d, 'title' => 'In build', 'status' => Task::STATUS_DEVELOPING]);
        $project->workSessions()->create(['title' => 'A session', 'slug' => 'a-session', 'occurred_on' => '2026-03-01']);

        LodestarServer::actingAs($user)
            ->tool(GetProjectTool::class, ['project' => 'lodestar'])
            ->assertOk()
            ->assertSee(['Ship the board', 'Control layer', 'A session'])
            ->assertSee(['"repositories"', '"deliverable_counts"', '"task_counts"', '"recent_sessions"'])
            // counts by phase: one deliverable, one task in build.
            ->assertSee('"build":1');

        // A sibling's project is not reachable, even by slug.
        $stranger = User::factory()->create();
        $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        LodestarServer::actingAs($user)
            ->tool(GetProjectTool::class, ['project' => 'x'])
            ->assertHasErrors();
    }
}
