<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scannable TL;DR fields alongside the long-form detail. Each pairs with its
 * existing detail column: the summary is what the UI shows by default (and the
 * board can scan); the full body/plan opens on demand. They are deliberate,
 * authored abstracts (by a human or by an AI via the MCP) — not auto-generated,
 * so nothing keeps them in lock-step with the detail beyond whoever edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('body_summary')->nullable()->after('body'); // TL;DR of body
            $table->text('plan_summary')->nullable()->after('plan'); // TL;DR of plan
        });

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->text('body_summary')->nullable()->after('body'); // TL;DR of body
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['body_summary', 'plan_summary']);
        });

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropColumn('body_summary');
        });
    }
};
