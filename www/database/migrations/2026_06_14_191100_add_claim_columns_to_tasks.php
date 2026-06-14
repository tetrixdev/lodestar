<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who currently holds a *-ing task, for visibility. An agent claims a `ready_*`
 * card over MCP (the atomic `ready_* → *-ing` flip) and stamps `claimed_by` (the
 * per-machine token name) + `claimed_at`. There is deliberately no lease /
 * heartbeat / auto-reaper: the happy flow is expected, and a stuck card is
 * returned to its queue by a human pressing "release" on the board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('claimed_by')->nullable()->after('status_changed_at');
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['claimed_by', 'claimed_at']);
        });
    }
};
