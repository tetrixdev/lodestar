<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project identity for the unified board: a short `code` (≤12 chars) and a
 * `color`, rendered as a chip on every card so cross-project cards are
 * distinguishable at a glance (task #69). Both nullable; the board falls back to
 * a derived code + a default colour when unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('code', 12)->nullable()->after('slug');
            $table->string('color', 9)->nullable()->after('code'); // #rrggbb / #rrggbbaa
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['code', 'color']);
        });
    }
};
