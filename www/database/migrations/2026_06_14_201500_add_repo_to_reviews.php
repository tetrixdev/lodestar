<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GitHub repo a review compares within ("owner/name"). Together with the
 * existing base_ref/head_ref it fully identifies the comparison Lodestar fetches
 * the authoritative changed-file list for (the GitHub compare API).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('repo')->nullable()->after('title'); // owner/name
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('repo');
        });
    }
};
