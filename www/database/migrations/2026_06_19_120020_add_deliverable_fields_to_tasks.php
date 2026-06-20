<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tie a task to a deliverable. `deliverable_id` is nullable — a task can still be
 * standalone (branch-per-task → base). `sub_id` is the per-deliverable sequence
 * (1,2,3…) used for the nested branch name D{deliverable}/T{sub_id}-slug.
 * `is_corrective` marks a task spawned from deliverable-level review feedback.
 * `needs_functional_review` gates whether this task gets a human functional
 * review (default true; refactor/doc tasks can skip — also powers "trivial
 * tasks auto-pass").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('deliverable_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();
            $table->unsignedInteger('sub_id')->nullable()->after('deliverable_id');
            $table->boolean('is_corrective')->default(false)->after('sub_id');
            $table->boolean('needs_functional_review')->default(true)->after('is_corrective');

            $table->unique(['deliverable_id', 'sub_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['deliverable_id', 'sub_id']);
            $table->dropConstrainedForeignId('deliverable_id');
            $table->dropColumn(['sub_id', 'is_corrective', 'needs_functional_review']);
        });
    }
};
