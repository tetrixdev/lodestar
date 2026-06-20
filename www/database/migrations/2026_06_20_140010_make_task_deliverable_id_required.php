<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every task belongs to a deliverable — no loose/standalone tasks ever (decision
 * of the redesign). Make `tasks.deliverable_id` a required FK (NOT NULL) with
 * `restrictOnDelete` (soft-delete is the only removal path, so this never fires
 * in practice; it's a belt-and-braces guard against orphaning data).
 *
 * Guard first: abort loudly if any loose task remains, so this can't run before
 * the data is cleaned (W1). Then rebuild the FK + unique. On both sqlite (tests)
 * and pgsql (dev) Laravel rebuilds the table for the `->change()`; the unique
 * `(deliverable_id, sub_id)` is dropped first and re-added after so the rebuild
 * doesn't choke on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $loose = DB::table('tasks')->whereNull('deliverable_id')->count();
        if ($loose > 0) {
            throw new RuntimeException(
                "Cannot make tasks.deliverable_id NOT NULL: {$loose} task(s) still have a null deliverable_id. "
                .'Attach every task to a deliverable first (see redesign W1).'
            );
        }

        // Drop the unique + FK that reference deliverable_id before changing it.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['deliverable_id', 'sub_id']);
            $table->dropForeign(['deliverable_id']);
        });

        // deliverable_id → NOT NULL.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('deliverable_id')->nullable(false)->change();
        });

        // Re-add the FK (now restrictOnDelete) and the unique.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('deliverable_id')->references('id')->on('deliverables')->restrictOnDelete();
            $table->unique(['deliverable_id', 'sub_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['deliverable_id', 'sub_id']);
            $table->dropForeign(['deliverable_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('deliverable_id')->nullable()->change();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('deliverable_id')->references('id')->on('deliverables')->nullOnDelete();
            $table->unique(['deliverable_id', 'sub_id']);
        });
    }
};
