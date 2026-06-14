<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative set of files a review's comparison changed — fetched from
 * the GitHub compare API, NOT supplied by the AI. The coverage guard asserts
 * every one of these is allocated to at least one review section, so a review
 * can only reach a human once it provably accounts for every changed file.
 *
 * `position` preserves GitHub's own ordering so the UI file-tree matches what a
 * reviewer sees on GitHub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('status');                 // added|modified|removed|renamed|...
            $table->string('old_path')->nullable();   // previous_filename for renames
            $table->integer('position')->default(0);  // GitHub's ordering
            $table->timestamps();

            $table->unique(['review_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_files');
    }
};
