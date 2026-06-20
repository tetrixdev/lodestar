<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured plan-review flow — the plan-side mirror of the code-review
 * walkthrough. Where a code review walks a GitHub diff section by section, a
 * plan review walks the planning agent's `plan` section by section.
 *
 * - `plan_review_sections`: the ordered walkthrough steps for one task's plan
 *   (the plan mirror of `review_sections`, minus the file-coverage machinery —
 *   a plan has no changed files).
 * - `tasks.plan_reviewer_id`: the human currently holding the plan review
 *   (the mirror of `reviews.assigned_to_user_id`), atomically self-assigned
 *   before they may sign off sections.
 * - `tasks.plan_rework_notes`: the compiled change request written back when a
 *   plan review requests changes (the plan mirror of `rework_notes`), which the
 *   planning agent reads on its next pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_review_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->string('title');
            $table->string('focus')->nullable();
            $table->text('context')->nullable();
            $table->jsonb('checks')->nullable();
            $table->string('status')->default('open');
            $table->string('decision')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('plan_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('plan_rework_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_reviewer_id');
            $table->dropColumn('plan_rework_notes');
        });

        Schema::dropIfExists('plan_review_sections');
    }
};
