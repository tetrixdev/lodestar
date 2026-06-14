<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ordered step of a review walkthrough — the data behind the HTML
 * demonstrator. `mode` is the review mode (skip | behavioural | direct |
 * direct_doc | mirror_guard); the section is reviewed top-to-bottom so context
 * rebuilds as you go. `status` carries the human's per-section sign-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->string('title');
            $table->string('mode')->default('direct'); // skip|behavioural|direct|direct_doc|mirror_guard
            $table->text('context')->nullable();        // "where this fits" — rebuilds the reviewer's knowledge
            $table->string('link')->nullable();         // what to open (a doc/file/route)
            $table->jsonb('checks')->nullable();        // ["what to check", ...]
            $table->string('status')->default('open');  // open | signed_off
            $table->text('note')->nullable();           // the human's comment / change request
            $table->timestamps();

            $table->index(['review_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_sections');
    }
};
