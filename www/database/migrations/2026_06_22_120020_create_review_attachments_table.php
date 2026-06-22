<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section review attachments (task #100 C): an image/file a reviewer pastes
 * or uploads against one review section, alongside the free-form comment. Files
 * live on a dedicated PRIVATE local disk `review-attachments` (never public) and
 * are streamed through a gated download route — only the `path`/metadata is
 * stored here. Cascades with its section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_attachments');
    }
};
