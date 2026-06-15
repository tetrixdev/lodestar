<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project secrets, split into two halves (see task #54):
 *  - `project_secret_requirements` — the KEYS a project needs to run (a manifest;
 *    not sensitive, visible to members).
 *  - `personal_secrets` — each user's own VALUES (encrypted at the app layer),
 *    optionally scoped to one project. An agent imports its user's values for a
 *    project's required keys via an out-of-MCP endpoint; nothing here is ever
 *    returned through the MCP/LLM channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_secret_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('description')->nullable();
            $table->boolean('is_secret')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('personal_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete(); // null = any project
            $table->string('key');
            $table->text('value'); // encrypted via the model cast
            $table->timestamps();

            $table->unique(['user_id', 'project_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_secrets');
        Schema::dropIfExists('project_secret_requirements');
    }
};
