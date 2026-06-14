<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review's comparison now happens within a first-class Repository, not a
 * free-text "owner/name" string. Replace `reviews.repo` with `repository_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('repo');
            $table->foreignId('repository_id')->nullable()->after('title')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_id');
            $table->string('repo')->nullable()->after('title');
        });
    }
};
