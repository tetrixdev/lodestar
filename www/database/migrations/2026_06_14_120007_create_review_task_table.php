<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking a Review to the Tasks it covers (many-to-many). A review can be
 * opened from either side: the review page lists its tasks, and a task card
 * links back to its review. Unique (review_id, task_id) so a pair is recorded
 * once; both sides cascade so the link disappears with either end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_task');
    }
};
