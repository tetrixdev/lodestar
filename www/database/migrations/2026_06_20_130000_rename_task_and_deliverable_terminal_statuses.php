<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W3 of the deliverable-only lifecycle redesign: drop the task `new` status and
 * rename the two terminals on BOTH tasks and deliverables.
 *
 *   tasks:        new -> ready_for_planning, done -> merged, merge_deploy -> merging
 *   deliverables: done -> merged, merge_deploy -> merging   (deliverable `new` kept)
 *
 * `status` is a plain string column on both tables (no enum / check constraint),
 * so this is a straight data rewrite of existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('status', 'new')->update(['status' => 'ready_for_planning']);
        DB::table('tasks')->where('status', 'done')->update(['status' => 'merged']);
        DB::table('tasks')->where('status', 'merge_deploy')->update(['status' => 'merging']);

        DB::table('deliverables')->where('status', 'done')->update(['status' => 'merged']);
        DB::table('deliverables')->where('status', 'merge_deploy')->update(['status' => 'merging']);
    }

    public function down(): void
    {
        // Tasks had no way to distinguish a former `new` from a `ready_for_planning`,
        // so the rollback only reverses the terminal renames (the safe, lossless part).
        DB::table('tasks')->where('status', 'merged')->update(['status' => 'done']);
        DB::table('tasks')->where('status', 'merging')->update(['status' => 'merge_deploy']);

        DB::table('deliverables')->where('status', 'merged')->update(['status' => 'done']);
        DB::table('deliverables')->where('status', 'merging')->update(['status' => 'merge_deploy']);
    }
};
