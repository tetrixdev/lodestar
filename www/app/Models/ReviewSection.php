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

    protected function casts(): array
    {
        return ['checks' => 'array', 'checked' => 'array'];
    }

    /**
     * The manual-test checklist as render-ready items: each carries its index,
     * label, and whether the human has ticked it. `checks` is the agent-authored
     * list of labels; `checked` is the human's persisted set of ticked indices —
     * the two are kept apart so re-authoring the checklist never wipes progress.
     *
     * @return list<array{index:int, label:string, done:bool}>
     */
    public function checklist(): array
    {
        $done = array_flip(array_map('intval', $this->checked ?? []));

        return collect($this->checks ?? [])
            ->values()
            ->map(fn ($label, $i) => [
                'index' => $i,
                'label' => (string) $label,
                'done' => isset($done[$i]),
            ])
            ->all();
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
}
