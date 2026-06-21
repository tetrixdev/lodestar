<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `stack` required. This is a Laravel-first instance, so every project must
 * declare its stack: backfill any untagged project to `laravel`, then make the
 * column NOT NULL with a `laravel` default (so the quick-create path and any
 * omitted insert still produce a tagged project).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->whereNull('stack')->update(['stack' => 'laravel']);

        Schema::table('projects', function (Blueprint $table) {
            $table->string('stack')->nullable(false)->default('laravel')->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('stack')->nullable()->default(null)->change();
        });
    }
};
