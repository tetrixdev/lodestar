<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\GitHubComparison;
use Illuminate\Console\Command;

/**
 * Enrich an existing review's stored comparison data. Reviews created before
 * patches/SHAs were stored have null `patch` on every `review_files` row and
 * null `base_sha`/`head_sha` on the review, so the file viewer can't render a
 * diff. This resolves+persists the SHAs (if null), re-fetches the GitHub
 * comparison, and updates each EXISTING file row (matched by path) with its
 * patch/additions/deletions.
 *
 * It never adds or removes file rows — the file set stays exactly as it was
 * (the coverage allocation against it must not change). Idempotent: re-running
 * just rewrites the same data.
 */
class BackfillReviewPatches extends Command
{
    protected $signature = 'reviews:backfill-patches {review : The review id}';

    protected $description = 'Backfill patch/additions/deletions and base/head SHAs onto an existing review\'s files.';

    public function handle(GitHubComparison $github): int
    {
        $review = Review::find((int) $this->argument('review'));
        if (! $review) {
            $this->error("Review #{$this->argument('review')} not found.");

            return self::FAILURE;
        }

        $comparisons = $review->comparisons()->with('repository')->get();
        if ($comparisons->isEmpty()) {
            $this->error("Review #{$review->id} has no comparison — nothing to fetch.");

            return self::FAILURE;
        }

        $updated = 0;
        $total = 0;
        $missing = [];

        foreach ($comparisons as $comparison) {
            $repo = $comparison->repository;
            if (! $repo || ! $comparison->base_ref || ! $comparison->head_ref) {
                $this->warn("Comparison #{$comparison->id} has no repo/base_ref/head_ref — skipped.");

                continue;
            }

            $token = $repo->token();
            $fullName = $repo->full_name;

            // Resolve + persist the SHAs if missing, so the viewer can fetch blobs.
            $shaUpdate = [];
            if (! $comparison->base_sha) {
                $shaUpdate['base_sha'] = $github->resolveSha($fullName, $comparison->base_ref, $token);
            }
            if (! $comparison->head_sha) {
                $shaUpdate['head_sha'] = $github->resolveSha($fullName, $comparison->head_ref, $token);
            }
            if ($shaUpdate !== []) {
                $comparison->update($shaUpdate);
                $this->info("[{$fullName}] Resolved SHAs: ".collect($shaUpdate)->map(fn ($v, $k) => "{$k}={$v}")->join(', '));
            }

            // Re-run the comparison and index by path.
            $files = collect($github->files($fullName, $comparison->base_ref, $comparison->head_ref, $token))
                ->keyBy('path');

            foreach ($comparison->files as $row) {
                $total++;
                $fresh = $files->get($row->path);
                if ($fresh === null) {
                    // The current comparison no longer reports this path — leave the
                    // row untouched (we don't change the file set).
                    $missing[] = $row->path;

                    continue;
                }

                $row->update([
                    'patch' => $fresh['patch'],
                    'additions' => $fresh['additions'],
                    'deletions' => $fresh['deletions'],
                ]);
                $updated++;
            }
        }

        $this->info("Updated {$updated} of {$total} file row(s) on review #{$review->id}.");
        if ($missing !== []) {
            $this->warn(count($missing).' stored file(s) are no longer in their comparison (left untouched): '
                .implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
