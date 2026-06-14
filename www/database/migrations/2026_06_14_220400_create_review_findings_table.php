<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A finding the AI-review raised within a section — described as a realistic
 * scenario + impact, with a severity and a human triage status. This is what
 * makes a review a conversation, not just a checklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_section_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('detail')->nullable();        // scenario + impact
            $table->string('severity')->default('minor'); // info|minor|major|critical
            $table->string('status')->default('open');    // open|must_fix|approved|dismissed
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_findings');
    }
};
