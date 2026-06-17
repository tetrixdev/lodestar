<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin the comparison's resolved commit SHAs at review-creation time. A ref like
 * "main" moves; the SHAs let the file viewer fetch the exact blob a diff was
 * taken against (and build stable GitHub blob links).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('base_sha')->nullable()->after('base_ref');
            $table->string('head_sha')->nullable()->after('head_ref');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['base_sha', 'head_sha']);
        });
    }
};
