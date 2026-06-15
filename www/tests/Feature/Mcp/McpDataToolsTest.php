<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\CreateReviewTool;
use App\Mcp\Tools\GetReviewTool;
use App\Mcp\Tools\UpsertProjectTool;
use App\Mcp\Tools\UpsertReviewSectionTool;
use App\Mcp\Tools\UpsertSessionTool;
use App\Mcp\Tools\UpsertTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpDataToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_project_creates_then_updates(): void
    {
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(UpsertProjectTool::class, ['name' => 'Lodestar', 'primary_goal' => 'Ship it'])
            ->assertOk();

        $project = $user->projects()->sole();
        $this->assertSame('lodestar', $project->slug);
        $this->assertSame('Ship it', $project->primary_goal);

        LodestarServer::actingAs($user)
            ->tool(UpsertProjectTool::class, ['id' => $project->id, 'description' => 'changed'])
            ->assertOk();

        $this->assertSame('changed', $project->fresh()->description);
    }

    public function test_upsert_task_defaults_to_new_and_can_be_addressed_by_slug(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'Card', 'category' => 'mcp'])
            ->assertOk();

        $task = $project->tasks()->sole();
        $this->assertSame(Task::STATUS_NEW, $task->status);
        $this->assertSame('mcp', $task->category);
    }

    public function test_upsert_task_refuses_to_create_a_card_past_the_backlog(): void
    {
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        // Creating straight into a working/gate state bypasses claim_task — rejected.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'X', 'status' => Task::STATUS_DEVELOPING])
            ->assertHasErrors();

        // A backlog entry state is allowed.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'Y', 'status' => Task::STATUS_READY_FOR_PLANNING])
            ->assertOk();
    }

    public function test_create_review_returns_url_and_links_only_owned_tasks(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_NEW]);

        // A task in someone else's project must NOT get linked.
        $stranger = User::factory()->create();
        $otherProject = $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        $foreign = $otherProject->tasks()->create(['title' => 'F', 'status' => Task::STATUS_NEW]);

        LodestarServer::actingAs($user)
            ->tool(CreateReviewTool::class, [
                'project' => 'p',
                'title' => 'First review',
                'task_ids' => [$task->id, $foreign->id],
            ])
            ->assertOk()
            ->assertSee(route('reviews.show', $project->reviews()->sole()));

        $review = $project->reviews()->sole();
        $this->assertEqualsCanonicalizing([$task->id], $review->tasks()->pluck('tasks.id')->all());
    }

    public function test_review_sections_append_and_get_review_reads_them_back(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft']);

        foreach (['direct', 'mirror_guard'] as $i => $mode) {
            LodestarServer::actingAs($user)
                ->tool(UpsertReviewSectionTool::class, [
                    'review_id' => $review->id,
                    'title' => 'Section '.$i,
                    'mode' => $mode,
                ])->assertOk();
        }

        LodestarServer::actingAs($user)
            ->tool(GetReviewTool::class, ['review_id' => $review->id])
            ->assertOk()
            ->assertSee('Section 0')
            ->assertSee('mirror_guard');

        // Sections append at max(position)+1 (first lands at 1, like board cards).
        $this->assertSame([1, 2], $review->sections()->pluck('position')->all());
    }

    public function test_upsert_session_creates_a_work_log(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(UpsertSessionTool::class, ['project' => 'p', 'title' => 'Did things'])
            ->assertOk();

        $this->assertSame('did-things', $project->workSessions()->sole()->slug);
    }

    public function test_upsert_task_requires_a_summary_when_detail_is_set(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        // body without body_summary → rejected.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'Card', 'body' => 'Long detail'])
            ->assertHasErrors();

        // plan without plan_summary → rejected.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['project' => 'p', 'title' => 'Card', 'plan' => 'A plan'])
            ->assertHasErrors();

        $this->assertSame(0, $project->tasks()->count());

        // Both halves present → accepted and stored.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, [
                'project' => 'p', 'title' => 'Card',
                'body' => 'Long detail', 'body_summary' => 'Short.',
                'plan' => 'A plan', 'plan_summary' => 'Plan TL;DR.',
            ])
            ->assertOk();

        $task = $project->tasks()->sole();
        $this->assertSame('Short.', $task->body_summary);
        $this->assertSame('Plan TL;DR.', $task->plan_summary);
    }

    public function test_session_and_report_require_a_summary_when_body_is_set(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);

        LodestarServer::actingAs($user)
            ->tool(UpsertSessionTool::class, ['project' => 'p', 'title' => 'S', 'body' => 'What happened'])
            ->assertHasErrors();

        LodestarServer::actingAs($user)
            ->tool(UpsertSessionTool::class, ['project' => 'p', 'title' => 'S', 'body' => 'What happened', 'body_summary' => 'Did X.'])
            ->assertOk();

        $this->assertSame('Did X.', $project->workSessions()->sole()->body_summary);
    }

    public function test_tools_are_scoped_to_the_token_user(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $otherProject = $stranger->projects()->create(['name' => 'X', 'slug' => 'x']);
        $foreign = $otherProject->tasks()->create(['title' => 'F', 'status' => Task::STATUS_NEW]);

        // Update a task that belongs to someone else → rejected, untouched.
        LodestarServer::actingAs($user)
            ->tool(UpsertTaskTool::class, ['id' => $foreign->id, 'title' => 'hacked'])
            ->assertHasErrors();

        $this->assertSame('F', $foreign->fresh()->title);
    }
}
