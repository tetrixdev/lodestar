<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `stack` to projects — the technology-stack tag (e.g. `laravel`) that drives
 * framework "stack pack" playbook composition (see Playbook::STACK_PACKS): a
 * tagged project gets that pack's structure guidance composed into its
 * plan/develop/ai_review prompts. Backfills Lodestar's own project to `laravel`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('stack')->nullable()->after('color');
        });

        // Lodestar itself is a Laravel app — tag it so it receives the laravel pack.
        DB::table('projects')->where('slug', 'lodestar')->update(['stack' => 'laravel']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('stack');
        });
    }
};
