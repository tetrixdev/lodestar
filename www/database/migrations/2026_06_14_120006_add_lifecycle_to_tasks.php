<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the open/doing/done board with the full lifecycle.
 *
 * Adds `status_changed_at` (when the card last entered its current status, the
 * basis for the "Nh in <status>" timer) and remaps the three legacy statuses:
 *   open  -> ready_for_planning
 *   doing -> developing
 *   done  -> merged
 *   cancelled -> cancelled (unchanged)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        // Backfill the timer from the row's last-touched time, then remap statuses.
        DB::table('tasks')->update(['status_changed_at' => DB::raw('updated_at')]);
        DB::table('tasks')->where('status', 'open')->update(['status' => 'ready_for_planning']);
        DB::table('tasks')->where('status', 'doing')->update(['status' => 'developing']);
        DB::table('tasks')->where('status', 'done')->update(['status' => 'merged']);
        // cancelled keeps its name.
    }

    public function down(): void
    {
        DB::table('tasks')->where('status', 'ready_for_planning')->update(['status' => 'open']);
        DB::table('tasks')->where('status', 'developing')->update(['status' => 'doing']);
        DB::table('tasks')->where('status', 'merged')->update(['status' => 'done']);
        // Other lifecycle statuses have no legacy equivalent; collapse them to a
        // safe legacy value so the old board can still render them.
        DB::table('tasks')->whereNotIn('status', ['open', 'doing', 'done', 'cancelled'])
            ->update(['status' => 'open']);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
