<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An open question the planning agent raised on a deliverable. Structured (not
 * prose) so the plan-review gate can enforce its rule: while any question is
 * unanswered the deliverable may only return to planning; once all are answered
 * the human may approve. `answered_at` is the simplest "is it answered?" signal.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('deliverable_questions');
    }
};
