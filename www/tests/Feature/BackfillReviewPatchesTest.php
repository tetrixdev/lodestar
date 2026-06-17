<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillReviewPatchesTest extends TestCase
{
    use RefreshDatabase;

    private function reviewNeedingBackfill(): Review
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $conn = $user->githubConnections()->create(['label' => 't', 'token' => 'tok', 'github_login' => 'u']);
        $repo = $conn->repositories()->create(['full_name' => 'o/r', 'default_branch' => 'main']);
        $project->repositories()->attach($repo);

        // A pre-backfill review: refs but no SHAs, file rows with null patch.
        $review = $project->reviews()->create([
            'title' => 'R', 'status' => 'in_review', 'repository_id' => $repo->id,
            'base_ref' => 'main', 'base_sha' => null, 'head_ref' => 'feat', 'head_sha' => null,
        ]);
        $review->files()->create(['path' => 'a.php', 'status' => 'modified', 'position' => 0, 'patch' => null]);
        $review->files()->create(['path' => 'b.php', 'status' => 'added', 'position' => 1, 'patch' => null]);

        return $review;
    }

    public function test_backfill_enriches_files_and_resolves_shas_without_changing_the_set(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/commits/main' => Http::response(['sha' => 'base-sha'], 200),
            'api.github.com/repos/o/r/commits/feat' => Http::response(['sha' => 'head-sha'], 200),
            'api.github.com/repos/o/r/compare/*' => Http::response([
                'files' => [
                    ['filename' => 'a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@', 'additions' => 4, 'deletions' => 1],
                    ['filename' => 'b.php', 'status' => 'added', 'patch' => '@@ -0,0 +1 @@', 'additions' => 9, 'deletions' => 0],
                    // A NEW path the original review never had — must NOT be added.
                    ['filename' => 'c.php', 'status' => 'added', 'patch' => '@@ -0,0 +1 @@', 'additions' => 1, 'deletions' => 0],
                ],
            ], 200),
        ]);

        $review = $this->reviewNeedingBackfill();

        $this->artisan('reviews:backfill-patches', ['review' => $review->id])
            ->assertSuccessful();

        $review->refresh();
        $this->assertSame('base-sha', $review->base_sha);
        $this->assertSame('head-sha', $review->head_sha);

        // The file set is unchanged (c.php was NOT added).
        $this->assertSame(2, $review->files()->count());

        $a = $review->files()->where('path', 'a.php')->sole();
        $this->assertSame('@@ -1 +1 @@', $a->patch);
        $this->assertSame(4, $a->additions);
        $this->assertSame(1, $a->deletions);

        $b = $review->files()->where('path', 'b.php')->sole();
        $this->assertSame(9, $b->additions);
    }

    public function test_backfill_is_idempotent(): void
    {
        Http::fake([
            'api.github.com/repos/o/r/commits/*' => Http::response(['sha' => 'x'], 200),
            'api.github.com/repos/o/r/compare/*' => Http::response([
                'files' => [['filename' => 'a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@', 'additions' => 1, 'deletions' => 1]],
            ], 200),
        ]);

        $review = $this->reviewNeedingBackfill();

        $this->artisan('reviews:backfill-patches', ['review' => $review->id])->assertSuccessful();
        $this->artisan('reviews:backfill-patches', ['review' => $review->id])->assertSuccessful();

        $this->assertSame(2, $review->files()->count());
        $this->assertSame('@@ -1 +1 @@', $review->files()->where('path', 'a.php')->sole()->patch);
    }

    public function test_backfill_fails_cleanly_without_a_repository(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft', 'base_ref' => 'main', 'head_ref' => 'feat']);

        $this->artisan('reviews:backfill-patches', ['review' => $review->id])
            ->assertFailed();
    }
}
