<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file/image a reviewer attached to one review section (task #100 C), alongside
 * the section's free-form comment. The bytes live on the PRIVATE
 * `review-attachments` disk; this row holds only the path + metadata. A `deleting`
 * hook removes the file from disk so the row owns its own side effect, and the
 * download URL points at the gated stream route (never a public URL).
 */
class ReviewAttachment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        // The row owns its file: deleting the row (directly or via the section
        // cascade) removes the blob from disk.
        static::deleting(function (ReviewAttachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ReviewSection::class, 'review_section_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Is this an inline-previewable image? */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /** The gated download URL (streams through ReviewController, access-checked). */
    public function url(): string
    {
        return route('reviews.sections.attachments.download', [
            $this->section->review_id,
            $this->review_section_id,
            $this->id,
        ]);
    }
}
