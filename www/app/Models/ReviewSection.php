<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered step of a review walkthrough (the data behind the HTML
 * demonstrator). `mode` is the review mode; `status` carries the human's
 * per-section sign-off.
 */
class ReviewSection extends Model
{
    protected $guarded = [];

    /** The review modes, per the protocol (vps-setup dev-method). */
    public const MODES = ['skip', 'behavioural', 'direct', 'direct_doc', 'mirror_guard'];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
