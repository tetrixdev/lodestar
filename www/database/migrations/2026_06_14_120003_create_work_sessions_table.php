<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A work-session log entry (the sessions/<date>-<slug>.md a project kept) — a
 * running history of what was done. Table is `work_sessions` (not `sessions`) so
 * it never collides with Laravel's framework session table / facade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('body')->nullable();        // the session summary (markdown)
            $table->date('occurred_on')->nullable(); // the in-world date of the session
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
