<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the "skill" concept to "playbook" at the schema level, to match the
 * model/MCP rename (skills collided with Claude Code's own "Skills"). Tables
 * skills → playbooks, skill_versions → playbook_versions, and the FK column
 * skill_id → playbook_id. The obsolete skill_bindings table (superseded by the
 * version-composition model) is dropped so it doesn't dangle a stale FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('skill_bindings');
        Schema::rename('skill_versions', 'playbook_versions');
        Schema::rename('skills', 'playbooks');
        Schema::table('playbook_versions', function (Blueprint $table) {
            $table->renameColumn('skill_id', 'playbook_id');
        });
    }

    public function down(): void
    {
        Schema::table('playbook_versions', function (Blueprint $table) {
            $table->renameColumn('playbook_id', 'skill_id');
        });
        Schema::rename('playbooks', 'skills');
        Schema::rename('playbook_versions', 'skill_versions');
    }
};
