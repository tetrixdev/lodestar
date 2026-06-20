<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliverable: the optional layer between a project and its tasks. It owns a
 * goal, a branch, and the review funnel. `concept` is the raw thing the user
 * wrote; `body` is the rewritten spec (planning playbook does the rewrite);
 * `plan` is the planning artifact that decomposes into child tasks. `branch` is
 * the deliverable integration branch (D{id}-slug); `base_branch` is what it is
 * cut from and diffed against. Mirrors the Task lifecycle so the board and
 * controls stay familiar (see App\Models\Deliverable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('category')->nullable();

            $table->text('concept')->nullable();          // raw, as the user wrote it
            $table->text('concept_summary')->nullable();
            $table->text('body')->nullable();             // rewritten spec (our format)
            $table->text('body_summary')->nullable();
            $table->text('plan')->nullable();             // planning artifact (markdown)
            $table->text('plan_summary')->nullable();

            $table->string('status')->default('new');
            $table->timestamp('status_changed_at')->nullable();
            $table->integer('position')->default(0);

            $table->string('branch')->nullable();         // D{id:06d}-slug
            $table->string('base_branch')->nullable();    // cut-from / diff-against

            // Agent claim (planning / deliverable ai_review / merge_deploy), mirrors tasks.
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverables');
    }
};
