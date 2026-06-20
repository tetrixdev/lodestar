<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\UpsertPlanReviewSectionTool;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpPlanReviewSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_agent_builds_a_plan_review_section_on_its_own_task(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_PLANNING]);

        LodestarServer::actingAs($user)->tool(UpsertPlanReviewSectionTool::class, [
            'task_id' => $task->id,
            'title' => 'Data model',
            'focus' => 'New table',
            'context' => 'Adds one table.',
            'checks' => ['Columns make sense', 'No drift'],
        ])->assertOk()->assertSee('"sections":1');

        $section = $task->planReviewSections()->sole();
        $this->assertSame('Data model', $section->title);
        $this->assertSame(['Columns make sense', 'No drift'], $section->checks);
    }

    public function test_upsert_updates_an_existing_section_by_id(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_PLANNING]);
        $section = $task->planReviewSections()->create(['position' => 0, 'title' => 'Old', 'status' => 'open']);

        LodestarServer::actingAs($user)->tool(UpsertPlanReviewSectionTool::class, [
            'task_id' => $task->id, 'id' => $section->id, 'title' => 'New title',
        ])->assertOk();

        $this->assertSame('New title', $section->fresh()->title);
        $this->assertSame(1, $task->planReviewSections()->count()); // updated, not appended
    }

    public function test_a_section_cannot_be_added_to_another_users_task(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_PLANNING]);

        LodestarServer::actingAs($intruder)->tool(UpsertPlanReviewSectionTool::class, [
            'task_id' => $task->id, 'title' => 'S',
        ])->assertHasErrors();

        $this->assertSame(0, $task->planReviewSections()->count());
    }
}
