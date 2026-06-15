<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Summary belongs on the VERSION, not the slot: a proposal changes title +
 * summary + body together, and the review/diff should show all three. Move it,
 * backfilling each version from the slot's current summary, then drop the slot
 * column so there's a single source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('title');
        });

        // Backfill: every existing version inherits its slot's summary.
        foreach (DB::table('skills')->whereNotNull('summary')->get() as $slot) {
            DB::table('skill_versions')->where('skill_id', $slot->id)->update(['summary' => $slot->summary]);
        }

        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('title');
        });
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
