<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-comparison reviews. A review used to carry exactly one comparison
 * inline (`repository_id` + `base_ref`/`head_ref` + the resolved SHAs), with its
 * `review_files` hanging straight off the review. To let one review span several
 * repositories, each comparison becomes a first-class `review_comparisons` row
 * (its own repo + base/head + SHAs), and `review_files` belong to a comparison.
 *
 * This migration: creates `review_comparisons`; folds each existing review's
 * inline comparison into one comparison row; re-points `review_files` onto that
 * row; and drops the now-moved comparison columns off `reviews`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('base_ref')->nullable();
            $table->string('base_sha')->nullable();
            $table->string('head_ref')->nullable();
            $table->string('head_sha')->nullable();
            $table->integer('position')->default(0); // order of comparisons in the review
            $table->timestamps();
        });

        // Files move from belonging to a review to belonging to a comparison.
        Schema::table('review_files', function (Blueprint $table) {
            $table->foreignId('review_comparison_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        // Backfill: every existing review with an inline comparison (a repo or a
        // base/head ref) gets one comparison row; its files re-point onto it.
        foreach (DB::table('reviews')->get() as $review) {
            $hasComparison = $review->repository_id !== null
                || ($review->base_ref ?? null) !== null
                || ($review->head_ref ?? null) !== null;

            if (! $hasComparison && DB::table('review_files')->where('review_id', $review->id)->doesntExist()) {
                continue;
            }

            $comparisonId = DB::table('review_comparisons')->insertGetId([
                'review_id' => $review->id,
                'repository_id' => $review->repository_id,
                'base_ref' => $review->base_ref,
                'base_sha' => $review->base_sha,
                'head_ref' => $review->head_ref,
                'head_sha' => $review->head_sha,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('review_files')->where('review_id', $review->id)
                ->update(['review_comparison_id' => $comparisonId]);
        }

        // Drop the old review_id column + its unique off review_files, and the
        // inline-comparison columns off reviews.
        Schema::table('review_files', function (Blueprint $table) {
            $table->dropUnique(['review_id', 'path']);
            $table->dropConstrainedForeignId('review_id');
            $table->unique(['review_comparison_id', 'path']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_id');
            $table->dropColumn(['base_ref', 'base_sha', 'head_ref', 'head_sha']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('repository_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->string('base_ref')->nullable();
            $table->string('base_sha')->nullable();
            $table->string('head_ref')->nullable();
            $table->string('head_sha')->nullable();
        });

        Schema::table('review_files', function (Blueprint $table) {
            $table->foreignId('review_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Fold the first comparison of each review back inline, re-point files.
        foreach (DB::table('review_comparisons')->orderBy('position')->get()->groupBy('review_id') as $reviewId => $comparisons) {
            $first = $comparisons->first();
            DB::table('reviews')->where('id', $reviewId)->update([
                'repository_id' => $first->repository_id,
                'base_ref' => $first->base_ref,
                'base_sha' => $first->base_sha,
                'head_ref' => $first->head_ref,
                'head_sha' => $first->head_sha,
            ]);
            foreach ($comparisons as $comparison) {
                DB::table('review_files')->where('review_comparison_id', $comparison->id)
                    ->update(['review_id' => $reviewId]);
            }
        }

        Schema::table('review_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_comparison_id');
            $table->unique(['review_id', 'path']);
        });

        Schema::dropIfExists('review_comparisons');
    }
};
