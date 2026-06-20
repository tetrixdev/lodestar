<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\UpsertTaskTool;
use App\Models\Project;
use App\Models\Deliverable;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The team-aware access rule, end to end: a teammate reaches a shared team
 * project (web + MCP), a non-member is refused, and a personal project
 * (team_id null) stays owner-only.
 */
class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{owner: User, member: User, team: Team, project: Project}
     */
    private function teamProject(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team = Team::create(['name' => 'Crew', 'owner_user_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'member', 'can_approve_prompts' => false]);

        $project = $owner->projects()->create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'team_id' => $team->id,
        ]);

        return compact('owner', 'member', 'team', 'project');
    }

    public function test_dashboard_surfaces_a_shared_team_projects_work(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();
        // A plan_review card surfaces in the dashboard's "Plans to review" bucket.
        $this->makeTask($project, ['title' => 'Needs a human', 'status' => Task::STATUS_PLAN_REVIEW]);

        $this->actingAs($member)->get(route('board'))->assertOk()->assertSee('Needs a human');
    }

    public function test_a_teammate_does_not_see_the_owners_personal_data(): void
    {
        ['owner' => $owner, 'member' => $member] = $this->teamProject();
        $owner->githubConnections()->create(['label' => 'owner-secret', 'token' => 't', 'github_login' => 'owneraccount']);
        $owner->createToken('owner-laptop');

        // Even with a shared project, GitHub connections + tokens stay personal.
        $this->actingAs($member)->get(route('github.index'))->assertOk()->assertDontSee('owneraccount');
        $this->actingAs($member)->get(route('agent-tokens.index'))->assertOk()->assertDontSee('owner-laptop');
        $this->assertSame(0, $member->githubConnections()->count());
        $this->assertSame(0, $member->tokens()->count());
    }

    // ── web: a teammate can reach a shared team project ──────────────────────

    public function test_team_member_can_view_a_teammates_team_project_board(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();

        $this->actingAs($member)
            ->get("/projects/{$project->id}")
            ->assertOk();
    }

    public function test_team_member_can_view_a_task_detail_in_a_team_project(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();
        $task = $this->makeTask($project, ['title' => 'Shared card', 'status' => Task::STATUS_READY_FOR_PLANNING]);

        $this->actingAs($member)
            ->get("/tasks/{$task->id}")
            ->assertOk()
            ->assertSee('Shared card');
    }

    public function test_team_member_can_view_a_review_in_a_team_project(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();
        $review = $project->reviews()->create(['title' => 'Team review', 'status' => 'draft']);

        $this->actingAs($member)
            ->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Team review');
    }

    public function test_team_project_appears_on_the_members_project_index(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();

        $this->actingAs($member)
            ->get('/projects')
            ->assertOk()
            ->assertSee($project->name);
    }

    // ── web: a non-member is still refused ───────────────────────────────────

    public function test_non_member_gets_403_on_a_team_project_board(): void
    {
        ['project' => $project] = $this->teamProject();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/projects/{$project->id}")
            ->assertForbidden();
    }

    public function test_non_member_gets_403_on_a_task_in_a_team_project(): void
    {
        ['project' => $project] = $this->teamProject();
        $task = $this->makeTask($project, ['title' => 'Secret', 'status' => Task::STATUS_READY_FOR_PLANNING]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/tasks/{$task->id}")
            ->assertForbidden();
    }

    // ── personal projects stay owner-only ────────────────────────────────────

    public function test_personal_project_is_still_owner_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        // team_id null → personal, backward-compatible owner-only access.
        $project = $owner->projects()->create(['name' => 'Mine', 'slug' => 'mine-'.uniqid()]);
        $this->assertNull($project->team_id);

        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
        $this->actingAs($other)->get("/projects/{$project->id}")->assertForbidden();
    }

    // ── MCP: the same rule applies to tools ──────────────────────────────────

    public function test_mcp_list_projects_shows_a_team_project_to_the_member(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();

        LodestarServer::actingAs($member)
            ->tool(ListProjectsTool::class, [])
            ->assertOk()
            ->assertSee($project->slug);
    }

    public function test_mcp_upsert_task_works_on_a_team_project_for_the_member(): void
    {
        ['member' => $member, 'project' => $project] = $this->teamProject();
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);

        LodestarServer::actingAs($member)
            ->tool(UpsertTaskTool::class, [
                'deliverable' => $d->id,
                'title' => 'Added by teammate',
                'status' => Task::STATUS_READY_FOR_PLANNING,
            ])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title' => 'Added by teammate',
        ]);
    }

    public function test_mcp_does_not_expose_a_team_project_to_a_non_member(): void
    {
        ['project' => $project] = $this->teamProject();
        $stranger = User::factory()->create();

        LodestarServer::actingAs($stranger)
            ->tool(ListProjectsTool::class, [])
            ->assertOk()
            ->assertDontSee($project->slug);
    }

    public function test_mcp_upsert_task_is_refused_for_a_non_member(): void
    {
        ['project' => $project] = $this->teamProject();
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $stranger = User::factory()->create();

        // A non-member cannot resolve the deliverable, so the tool errors out and
        // no card is created.
        LodestarServer::actingAs($stranger)
            ->tool(UpsertTaskTool::class, [
                'deliverable' => $d->id,
                'title' => 'Intruder card',
                'status' => Task::STATUS_READY_FOR_PLANNING,
            ])
            ->assertHasErrors();

        $this->assertDatabaseMissing('tasks', ['title' => 'Intruder card']);
    }
}
