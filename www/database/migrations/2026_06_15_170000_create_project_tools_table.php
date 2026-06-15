<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project tools an agent should have available in its workspace:
 *  - `program` — something installed on the machine (e.g. pandoc): `check` tells
 *    the agent how to detect it, `run` how to install it if missing.
 *  - `command` — a small reusable script/alias (e.g. a thin Sentry wrapper so the
 *    agent doesn't load a huge MCP): `run` is the script body the agent installs
 *    into its workspace `bin/` and calls per `description`.
 * Fetched out-of-MCP (like secrets) so long scripts never eat the agent's context.
 * Approver-managed — running project-supplied shell is a trusted-team boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                 // program | command
            $table->string('name');
            $table->string('description')->nullable();
            $table->text('check')->nullable();      // program: presence check; command: unused
            $table->text('run');                    // program: install command; command: script body
            $table->timestamps();

            $table->unique(['project_id', 'kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tools');
    }
};
