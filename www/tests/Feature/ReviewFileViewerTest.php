<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\CreateReviewTool;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use App\Services\GitHubComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewFileViewerTest extends TestCase
{
    use RefreshDatabase;

    /** Link a repository (through a connection) to a project. */
    private function linkRepo(Project $project, string $full = 'o/r'): void
    {
        $conn = $project->user->githubConnections()->create([
            'label' => 'test', 'token' => 'tok', 'github_login' => 'tester',
        ]);
        $repo = $conn->repositories()->create(['full_name' => $full, 'default_branch' => 'main']);
        $project->repositories()->attach($repo);
    }

    // --- GitHubComparison ----------------------------------------------------

    public function test_files_surfaces_patch_additions_and_deletions(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'files' => [
                    ['filename' => 'a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@', 'additions' => 3, 'deletions' => 1],
                    ['filename' => 'img.png', 'status' => 'added'], // binary: no patch
                ],
            ], 200),
        ]);

        $files = (new GitHubComparison)->files('o/r', 'main', 'feat', 'tok');

        $this->assertSame('@@ -1 +1 @@', $files[0]['patch']);
        $this->assertSame(3, $files[0]['additions']);
        $this->assertSame(1, $files[0]['deletions']);
        $this->assertNull($files[1]['patch']); // binary → null patch
        $this->assertSame(0, $files[1]['additions']);
    }

    public function test_resolve_sha_returns_the_commit_sha(): void
    {
        Http::fake(['api.github.com/repos/o/r/commits/*' => Http::response(['sha' => 'abc123'], 200)]);

        $this->assertSame('abc123', (new GitHubComparison)->resolveSha('o/r', 'main', 'tok'));
    }

    public function test_blob_decodes_base64_and_flags_binary(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/ok.txt*' => Http::response([
                'encoding' => 'base64', 'size' => 5, 'content' => base64_encode('hello'),
            ], 200),
            'api.github.com/repos/o/r/contents/bin*' => Http::response([
                'encoding' => 'base64', 'size' => 3, 'content' => base64_encode("\xff\xfe\x00"),
            ], 200),
        ]);

        $svc = new GitHubComparison;
        $ok = $svc->blob('o/r', 'sha', 'ok.txt', 'tok');
        $this->assertSame('hello', $ok['content']);
        $this->assertFalse($ok['binary']);

        $bin = $svc->blob('o/r', 'sha', 'bin', 'tok');
        $this->assertNull($bin['content']);
        $this->assertTrue($bin['binary']);
    }

    public function test_blob_treats_too_large_error_as_oversized(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'This file is too large to fetch'], 403)]);

        $blob = (new GitHubComparison)->blob('o/r', 'sha', 'big.bin', 'tok');
        $this->assertTrue($blob['too_large']);
        $this->assertNull($blob['content']);
    }

    // --- create_review persistence ------------------------------------------

    public function test_create_review_persists_shas_and_per_file_patch(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/commits/main' => Http::response(['sha' => 'base-sha'], 200),
            'api.github.com/repos/o/r/commits/feat' => Http::response(['sha' => 'head-sha'], 200),
            'api.github.com/repos/o/r/compare/*' => Http::response([
                'files' => [['filename' => 'a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@', 'additions' => 2, 'deletions' => 0]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $this->linkRepo($project);

        LodestarServer::actingAs($user)->tool(CreateReviewTool::class, [
            'project' => 'p', 'title' => 'R', 'repo' => 'o/r', 'base_ref' => 'main', 'head_ref' => 'feat',
        ])->assertOk();

        $review = $project->reviews()->sole();
        $comparison = $review->comparisons()->sole();
        $this->assertSame('base-sha', $comparison->base_sha);
        $this->assertSame('head-sha', $comparison->head_sha);

        $file = $review->files()->sole();
        $this->assertSame('@@ -1 +1 @@', $file->patch);
        $this->assertSame(2, $file->additions);
    }

    // --- file endpoint -------------------------------------------------------

    private function reviewWithFile(User $user, array $fileAttrs = [], array $reviewAttrs = []): Review
    {
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p'.uniqid()]);
        $this->linkRepo($project);
        $repo = $project->repositories()->first();

        $review = $project->reviews()->create(array_merge([
            'title' => 'R', 'status' => 'in_review',
        ], $reviewAttrs));

        $comparison = $review->comparisons()->create([
            'repository_id' => $repo->id, 'position' => 0,
            'base_ref' => 'main', 'base_sha' => 'base-sha', 'head_ref' => 'feat', 'head_sha' => 'head-sha',
        ]);

        $comparison->files()->create(array_merge([
            'path' => 'a.php', 'status' => 'modified', 'position' => 0,
            'patch' => "@@ -1,2 +1,2 @@\n-old\n+new\n ctx", 'additions' => 1, 'deletions' => 1,
        ], $fileAttrs));

        return $review;
    }

    public function test_file_endpoint_requires_access(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $review = $this->reviewWithFile($owner);
        $file = $review->files()->first();

        $this->actingAs($stranger)
            ->get(route('reviews.files.show', [$review, $file]))
            ->assertForbidden();
    }

    public function test_diff_mode_renders_stored_patch_without_calling_github(): void
    {
        Http::fake(); // any GitHub call would now record — assert none happen.

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=diff')
            ->assertOk()
            ->assertSee('new')
            ->assertSee('old');

        Http::assertNothingSent();
    }

    public function test_null_patch_falls_back_to_github_link(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'img.png', 'status' => 'added', 'patch' => null]);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=diff')
            ->assertOk()
            ->assertSee('https://github.com/o/r/blob/head-sha/img.png');

        Http::assertNothingSent();
    }

    public function test_full_mode_fetches_blob_and_renders(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/a.php*' => Http::response([
                'encoding' => 'base64', 'size' => 12, 'content' => base64_encode("new\nctx\n"),
            ], 200),
        ]);

        $user = User::factory()->create();
        // Added file → no base fetch, all lines render as the head blob.
        $review = $this->reviewWithFile($user, ['path' => 'a.php', 'status' => 'added']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=full')
            ->assertOk()
            ->assertSee('ctx');

        Http::assertSentCount(1);
    }

    public function test_preview_mode_renders_markdown(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/*' => Http::response([
                'encoding' => 'base64', 'size' => 9, 'content' => base64_encode("# Title\n"),
            ], 200),
        ]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'README.md', 'status' => 'added']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=preview')
            ->assertOk()
            ->assertSee('<h1', false); // markdown rendered to HTML
    }

    public function test_full_mode_binary_blob_falls_back_to_github(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/*' => Http::response([
                'encoding' => 'base64', 'size' => 3, 'content' => base64_encode("\xff\xfe\x00"),
            ], 200),
        ]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'a.bin', 'status' => 'added']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=full')
            ->assertOk()
            ->assertSee('https://github.com/o/r/blob/head-sha/a.bin');
    }

    // --- rich (markdown) mode ------------------------------------------------

    public function test_rich_mode_added_markdown_renders_clean_document(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/*' => Http::response([
                'encoding' => 'base64', 'size' => 9, 'content' => base64_encode("# Heading\n\nbody text\n"),
            ], 200),
        ]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'README.md', 'status' => 'added']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=rich')
            ->assertOk()
            ->assertSee('<h1>Heading</h1>', false)
            ->assertDontSee('<ins', false); // an added file is not wholly inserted
    }

    public function test_rich_mode_modified_markdown_weaves_ins_del(): void
    {
        // base fetch (base-sha) + head fetch (head-sha) for a modified file.
        Http::fake([
            'api.github.com/repos/o/r/contents/README.md?ref=base-sha' => Http::response([
                'encoding' => 'base64', 'size' => 9, 'content' => base64_encode("hello world\n"),
            ], 200),
            'api.github.com/repos/o/r/contents/README.md?ref=head-sha' => Http::response([
                'encoding' => 'base64', 'size' => 9, 'content' => base64_encode("hello brave world\n"),
            ], 200),
        ]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'README.md', 'status' => 'modified']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=rich')
            ->assertOk()
            ->assertSee('<ins', false)
            ->assertSee('brave');
    }

    public function test_rich_mode_removed_markdown_renders_all_deleted(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/contents/*' => Http::response([
                'encoding' => 'base64', 'size' => 9, 'content' => base64_encode("# Old doc\n"),
            ], 200),
        ]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, ['path' => 'GONE.md', 'status' => 'removed']);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=rich')
            ->assertOk()
            ->assertSee('<del', false)
            ->assertSee('Old doc');
    }

    public function test_rich_mode_falls_back_to_stored_patch_when_blob_unfetchable(): void
    {
        // GitHub blob fetch errors → rich degrades to the raw stored patch
        // (which the fixture provides), not a GitHub-only dead end.
        Http::fake(['api.github.com/*' => Http::response(['message' => 'boom'], 500)]);

        $user = User::factory()->create();
        $review = $this->reviewWithFile($user, [
            'path' => 'README.md', 'status' => 'modified',
            'patch' => "@@ -1 +1 @@\n-old\n+new",
        ]);
        $file = $review->files()->first();

        $this->actingAs($user)
            ->get(route('reviews.files.show', [$review, $file]).'?mode=rich')
            ->assertOk()
            ->assertSee('new')
            ->assertSee('old');
    }
}
