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

    /**
     * The file gate is a small BLACKLIST, not a whitelist (task #100 #3): a
     * reviewer may attach almost anything (docx, xlsx, pdf, images incl. svg,
     * zip, logs…). We BLOCK only executable / installer / script types by
     * extension — these are the things that are dangerous to hand back to a
     * human who might run them. SVG is safe to allow because the download is
     * always forced-download + nosniff (never rendered inline; see
     * ReviewController::downloadAttachment).
     */
    public const BLOCKED_EXTENSIONS = [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'ps1',
        'sh', 'jar', 'app', 'deb', 'dmg', 'apk', 'vbs', 'wsf',
    ];

    /** Max upload size, in kilobytes (~25MB) — used by the upload validator. */
    public const MAX_KILOBYTES = 25600;

    /**
     * The safe Content-Type to serve for a given validated extension. Anything
     * not mapped is served as a generic binary stream. Images are listed so the
     * browser can still show a thumbnail off the gated URL — but the response is
     * ALWAYS Content-Disposition: attachment + nosniff, so even an SVG cannot
     * execute script in our origin.
     */
    private const SAFE_CONTENT_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'md' => 'text/plain', 'log' => 'text/plain',
        'csv' => 'text/csv', 'json' => 'application/json', 'zip' => 'application/zip',
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

    /** Is this an image (drives the inline thumbnail in the UI only — never inline serving)? */
    public function isImage(): bool
    {
        return in_array(
            strtolower((string) ($this->extension ?? '')),
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'svg'],
            true,
        );
    }

    /**
     * The Content-Type to serve, derived from the VALIDATED extension (never the
     * client-supplied mime). Unknown extensions stream as a generic binary blob.
     */
    public function safeContentType(): string
    {
        return self::SAFE_CONTENT_TYPES[strtolower((string) ($this->extension ?? ''))]
            ?? 'application/octet-stream';
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

    /**
     * The out-of-MCP API download URL an agent fetches with its Bearer token
     * (task #100 #7) — surfaced in get_review so the agent can read a human's
     * attachment without it entering the LLM channel.
     */
    public function apiUrl(): string
    {
        return route('api.review-attachments.show', $this->id);
    }
}
