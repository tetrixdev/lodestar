<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move `mode` (append|overwrite) from the slot onto the VERSION, like summary —
 * so flipping append↔overwrite is a reviewed proposal shown in the diff, not an
 * out-of-band toggle. After this the slot is pure identity (scope·owner·key) and
 * the version holds all content (title·summary·mode·body).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->string('mode')->default('append')->after('summary'); // append|overwrite
        });

        // Backfill: every version inherits its slot's current mode.
        foreach (DB::table('skills')->get() as $slot) {
            DB::table('skill_versions')->where('skill_id', $slot->id)->update(['mode' => $slot->mode]);
        }

        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('mode')->default('append')->after('key');
        });
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
