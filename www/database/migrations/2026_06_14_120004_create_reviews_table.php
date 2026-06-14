<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review of a change against a base (e.g. a branch against main). The
 * human-facing walkthrough is rendered from its ordered `review_sections`. An
 * agent prepares it via MCP and hands back its URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('base_ref')->nullable();    // e.g. main
            $table->string('head_ref')->nullable();    // e.g. feat/x  (the intended comparison)
            $table->string('status')->default('draft'); // draft | in_review | done
            $table->text('intro')->nullable();          // the "assume you forgot everything" preamble
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
