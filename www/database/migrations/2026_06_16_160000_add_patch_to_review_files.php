<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GitHub compare response already carries each file's unified-diff `patch`
 * (plus additions/deletions counts) — we now persist it so the review's
 * changed-file viewer can render the diff with NO further GitHub call. Binary or
 * oversized files have no patch, so it stays nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_files', function (Blueprint $table) {
            $table->longText('patch')->nullable()->after('position');
            $table->unsignedInteger('additions')->default(0)->after('patch');
            $table->unsignedInteger('deletions')->default(0)->after('additions');
        });
    }

    public function down(): void
    {
        Schema::table('review_files', function (Blueprint $table) {
            $table->dropColumn(['patch', 'additions', 'deletions']);
        });
    }
};
