<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-task activity log: status moves, claims/releases, reviews, comments —
 * who did what, when. Append-only; rendered as a timeline on the task detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('type');            // status_changed|claimed|released|review_created|commented|...
            $table->string('actor')->nullable(); // human name or agent id
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
    }
};
