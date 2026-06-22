<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Technical-architecture incomplete" flag for plan reviews (task #100 A).
 * When true (the AI could not fully plan the technical side — too many open
 * questions), the human's only conclude outcome is return-to-planning; the
 * approve-to-dev path is disabled regardless of the section decisions.
 *
 * review_type is already a free string column (default `code`), so the new value
 * `plan` needs no schema change — only this boolean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('plan_incomplete')->default(false)->after('review_type');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('plan_incomplete');
        });
    }
};
