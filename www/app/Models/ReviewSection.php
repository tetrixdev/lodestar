<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ordered step of a review walkthrough (the data behind the HTML
 * demonstrator). `mode` is the review mode; `status` carries the human's
 * per-section sign-off. A section also declares which changed files it covers
 * (the coverage guard requires every file land in at least one section).
 */
class ReviewSection extends Model
{
    protected $guarded = [];

    /** The review modes, per the protocol (vps-setup dev-method). */
    public const MODES = ['skip', 'behavioural', 'direct', 'direct_doc', 'mirror_guard'];

    /**
     * Section kind, orthogonal to `mode`: frames a change as input→output so the
     * functional review (and the plan) cover every surface, including invisible
     * outputs that need a dry-run preview. See docs/deliverable-workflow.md §5.
     */
    public const KIND_INPUT_SCREEN = 'input_screen';

    public const KIND_COMMAND = 'command';

    public const KIND_OUTBOUND_EFFECT = 'outbound_effect';   // mail / webhook / 3rd-party sync

    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_INPUT_SCREEN,
        self::KIND_COMMAND,
        self::KIND_OUTBOUND_EFFECT,
        self::KIND_OTHER,
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'stale' => 'boolean',
        ];
    }

    /**
     * Mark this section dirty for re-review: reset the human's decision, flag it
     * stale, and record what changed / when. The human re-walks only flagged
     * sections; untouched ones keep their prior decision. This is the single
     * mechanism behind both "flag affected sections after a merge" and incremental
     * re-review (docs §5).
     */
    public function markStale(string $changeNote): void
    {
        $this->forceFill([
            'decision' => null,
            'stale' => true,
            'change_note' => $changeNote,
        ])->save();
    }

    /** Clear the stale flag once the section has been re-decided. */
    public function clearStale(): void
    {
        $this->forceFill(['stale' => false])->save();
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** The changed files this section covers. */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(ReviewFile::class, 'review_file_section');
    }

    /** AI-raised findings within this section. */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class)->orderBy('position');
    }

    /** Reviewer-uploaded files/images for this section (task #100 C). */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReviewAttachment::class)->latest('id');
    }
}
