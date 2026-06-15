<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-line summary on the skill slot — "what this skill is for / when to use
 * it". Drives the catalog of named skills injected into the composed `main`
 * prompt so an agent knows what it can load on demand. Lives on the slot (not the
 * version): it's stable catalog metadata, editable by an approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('summary')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
