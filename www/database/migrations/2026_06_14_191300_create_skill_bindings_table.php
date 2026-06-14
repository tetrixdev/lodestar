<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which skill THIS user's loop runs for a phase. `project_id` null = the user's
 * default for every project; a row with a project overrides it for that project.
 * No row at all → the loop falls back to the current system skill for the phase.
 * So "push a system-skill update to everyone" is automatic: anyone not bound to
 * a fork picks up the new system version on their next `get_skill`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phase'); // plan | develop | ai_review | merge
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One binding per (user, project-or-default, phase).
            $table->unique(['user_id', 'project_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_bindings');
    }
};
