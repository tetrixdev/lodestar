<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the open/doing/done board with the full 13-status lifecycle.
 *
 * Adds `status_changed_at` (when the card last entered its current status, the
 * basis for the "Nh in <status>" timer) and remaps the three legacy statuses:
 *   open  -> new
 *   doing -> developing
 *   done  -> done       (unchanged)
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
        DB::table('tasks')->where('status', 'open')->update(['status' => 'new']);
        DB::table('tasks')->where('status', 'doing')->update(['status' => 'developing']);
        // done / cancelled keep their names.
    }

    public function down(): void
    {
        DB::table('tasks')->where('status', 'new')->update(['status' => 'open']);
        DB::table('tasks')->where('status', 'developing')->update(['status' => 'doing']);
        // Other lifecycle statuses have no legacy equivalent; collapse them to a
        // safe legacy value so the old board can still render them.
        DB::table('tasks')->whereNotIn('status', ['open', 'doing', 'done', 'cancelled'])
            ->update(['status' => 'open']);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
