<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\AdvanceTaskTool;
use App\Mcp\Tools\CreateReviewTool;
use App\Mcp\Tools\UpsertReviewSectionTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpReviewCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCompare(array $filenames): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'files' => array_map(fn ($name) => ['filename' => $name, 'status' => 'modified'], $filenames),
            ], 200),
        ]);
    }

    /** Link a repository (through a connection) to a project so create_review can resolve it. */
    private function linkRepo(Project $project, string $full = 'o/r'): void
    {
        $conn = $project->user->githubConnections()->create([
            'label' => 'test', 'token' => 'tok', 'github_login' => 'tester',
        ]);
        $repo = $conn->repositories()->create(['full_name' => $full, 'default_branch' => 'main']);
        $project->repositories()->attach($repo);
    }

    public function test_create_review_pulls_the_authoritative_file_list_from_github(): void
    {
        $this->fakeCompare(['app/A.php', 'app/B.php', 'docs/C.md']);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);

        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r',
            'base_ref' => 'main', 'head_ref' => 'feat/x',
        ])->assertOk()->assertSee('"total":3'); // create_review now returns coverage (total + uncovered list)

        $review = $project->reviews()->sole();
        $this->assertSame(['app/A.php', 'app/B.php', 'docs/C.md'], $review->files()->pluck('path')->all());
        $this->assertFalse($review->isFullyCovered()); // nothing allocated yet
    }

    public function test_a_section_can_only_cover_files_from_the_comparison(): void
    {
        $this->fakeCompare(['app/A.php', 'app/B.php']);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r', 'base_ref' => 'main', 'head_ref' => 'h',
        ]);
        $review = $project->reviews()->sole();

        // A path not in the comparison is rejected.
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S', 'mode' => 'direct',
            'files' => ['app/A.php', 'app/GHOST.php'],
        ])->assertHasErrors();

        // Real paths are allocated.
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S', 'mode' => 'direct',
            'files' => ['app/A.php', 'app/B.php'],
        ])->assertOk();

        $this->assertTrue($review->fresh()->isFullyCovered());
    }

    public function test_advance_to_human_review_is_blocked_until_every_file_is_covered(): void
    {
        $this->fakeCompare(['app/A.php', 'app/B.php']);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_AI_REVIEW]);

        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r', 'base_ref' => 'main', 'head_ref' => 'h',
            'task_ids' => [$task->id],
        ]);
        $review = $project->reviews()->sole();

        // Only one of two files covered → the gate refuses the hand-off.
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S1', 'mode' => 'direct', 'files' => ['app/A.php'],
        ]);
        LodestarServer::actingAs($user)->tool(AdvanceTaskTool::class, [
            'task_id' => $task->id, 'to' => Task::STATUS_HUMAN_REVIEW,
        ])->assertHasErrors();
        $this->assertSame(Task::STATUS_AI_REVIEW, $task->fresh()->status);

        // Cover the rest → the hand-off succeeds.
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S2', 'mode' => 'behavioural', 'files' => ['app/B.php'],
        ]);
        LodestarServer::actingAs($user)->tool(AdvanceTaskTool::class, [
            'task_id' => $task->id, 'to' => Task::STATUS_HUMAN_REVIEW,
        ])->assertOk();
        $this->assertSame(Task::STATUS_HUMAN_REVIEW, $task->fresh()->status);
    }

    public function test_human_review_requires_at_least_one_linked_review(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);
        // ai_review card with NO linked review at all.
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_AI_REVIEW]);

        LodestarServer::actingAs($user)->tool(AdvanceTaskTool::class, [
            'task_id' => $task->id, 'to' => Task::STATUS_HUMAN_REVIEW,
        ])->assertHasErrors();
        $this->assertSame(Task::STATUS_AI_REVIEW, $task->fresh()->status);
    }

    public function test_a_handed_off_review_is_frozen_against_further_edits(): void
    {
        $this->fakeCompare(['app/A.php']);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_AI_REVIEW]);
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r', 'base_ref' => 'm', 'head_ref' => 'h',
            'task_ids' => [$task->id],
        ]);
        $review = $project->reviews()->sole();
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S', 'mode' => 'direct', 'files' => ['app/A.php'],
        ]);

        // Hand off → review freezes to in_review.
        LodestarServer::actingAs($user)->tool(AdvanceTaskTool::class, [
            'task_id' => $task->id, 'to' => Task::STATUS_HUMAN_REVIEW,
        ])->assertOk();
        $this->assertSame('in_review', $review->fresh()->status);

        // Further section edits are now rejected — coverage can't silently re-open.
        LodestarServer::actingAs($user)->tool(UpsertReviewSectionTool::class, [
            'review_id' => $review->id, 'title' => 'S2', 'mode' => 'skip',
        ])->assertHasErrors();
    }

    public function test_a_large_diff_is_paginated_and_fully_retrieved(): void
    {
        // GitHub pages files 100 at a time; the service must walk pages. Fake
        // three pages (100, 100, 50) = 250 files, keyed by the ?page= query.
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
            $page = (int) ($q['page'] ?? 1);
            $count = $page <= 2 ? 100 : ($page === 3 ? 50 : 0);
            $files = [];
            for ($i = 0; $i < $count; $i++) {
                $files[] = ['filename' => "p{$page}_f{$i}.php", 'status' => 'modified'];
            }

            return Http::response(['files' => $files], 200);
        });

        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);

        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r', 'base_ref' => 'm', 'head_ref' => 'h',
        ])->assertOk()->assertSee('"total":250'); // coverage.total = the full file count

        $this->assertSame(250, $project->reviews()->sole()->files()->count());
    }

    public function test_a_doc_only_review_with_no_files_is_trivially_complete(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_AI_REVIEW]);

        // No base/head → no file fetch, no files.
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'task_ids' => [$task->id],
        ])->assertOk();

        LodestarServer::actingAs($user)->tool(AdvanceTaskTool::class, [
            'task_id' => $task->id, 'to' => Task::STATUS_HUMAN_REVIEW,
        ])->assertOk();
        $this->assertSame(Task::STATUS_HUMAN_REVIEW, $task->fresh()->status);
    }

    public function test_a_second_review_is_refused_while_one_is_still_open(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => Task::STATUS_AI_REVIEW]);

        // First review opens fine.
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'Round one', 'task_ids' => [$task->id],
        ])->assertOk();

        // Second one, while the first has no outcome yet, is refused.
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'Round two', 'task_ids' => [$task->id],
        ])->assertHasErrors();
        $this->assertSame(1, $task->reviews()->count(), 'no second review was created');

        // Conclude the first; a fresh review for the next round is then allowed.
        $task->reviews()->first()->update(['outcome' => 'approved', 'status' => 'done']);
        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'Round two', 'task_ids' => [$task->id],
        ])->assertOk();
        $this->assertSame(2, $task->reviews()->count());
    }
}
