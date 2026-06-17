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

        $repo = $review->repository;
        if (! $repo) {
            $this->error("Review #{$review->id} has no linked repository — nothing to fetch.");

            return self::FAILURE;
        }
        if (! $review->base_ref || ! $review->head_ref) {
            $this->error("Review #{$review->id} has no base_ref/head_ref comparison to re-fetch.");

            return self::FAILURE;
        }

        $token = $repo->token();
        $fullName = $repo->full_name;

        // Resolve + persist the SHAs if missing, so the viewer can fetch blobs.
        $shaUpdate = [];
        if (! $review->base_sha) {
            $shaUpdate['base_sha'] = $github->resolveSha($fullName, $review->base_ref, $token);
        }
        if (! $review->head_sha) {
            $shaUpdate['head_sha'] = $github->resolveSha($fullName, $review->head_ref, $token);
        }
        if ($shaUpdate !== []) {
            $review->update($shaUpdate);
            $this->info('Resolved SHAs: '.collect($shaUpdate)->map(fn ($v, $k) => "{$k}={$v}")->join(', '));
        }

        // Re-run the comparison and index by path.
        $files = collect($github->files($fullName, $review->base_ref, $review->head_ref, $token))
            ->keyBy('path');

        $updated = 0;
        $missing = [];
        foreach ($review->files as $row) {
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

        $this->info("Updated {$updated} of {$review->files->count()} file row(s) on review #{$review->id}.");
        if ($missing !== []) {
            $this->warn(count($missing).' stored file(s) are no longer in the comparison (left untouched): '
                .implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
