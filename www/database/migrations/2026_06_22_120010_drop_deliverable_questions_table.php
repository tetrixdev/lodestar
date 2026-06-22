<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the deliverable_questions table (task #100 A). Open questions are no longer
 * a deliverable-level mechanism — they now live as FINDINGS on a task's plan
 * review (a `plan`-type Review), so a question is answered in the same unified
 * walkthrough as the rest of the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('deliverable_questions');
    }

    public function down(): void
    {
        Schema::create('deliverable_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }
};
