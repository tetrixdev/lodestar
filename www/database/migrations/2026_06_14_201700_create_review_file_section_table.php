<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which review section(s) cover which changed file. Many-to-many: a file may be
 * covered by several sections, and the coverage guard requires each file be
 * covered by at least one. Both sides cascade-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_file_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_section_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_file_id', 'review_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_file_section');
    }
};
