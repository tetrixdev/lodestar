<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a review polymorphic over its target. `scope` is 'task' (today's reviews,
 * still linked via the review_task pivot) or 'deliverable' (linked via
 * `deliverable_id`). `review_type` separates the cheap per-task functional review
 * from the technical code/architecture review. `base_branch` records what a
 * deliverable review diffs against (its diff is base_branch...deliverable.branch);
 * task reviews keep using the existing shas. The MCP review tools branch on
 * `scope` — they are not forked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('scope')->default('task')->after('project_id');        // task|deliverable
            $table->foreignId('deliverable_id')->nullable()->after('scope')
                ->constrained()->nullOnDelete();
            $table->string('review_type')->default('code')->after('deliverable_id'); // functional|code|architecture
            $table->string('base_branch')->nullable()->after('review_type');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deliverable_id');
            $table->dropColumn(['scope', 'review_type', 'base_branch']);
        });
    }
};
