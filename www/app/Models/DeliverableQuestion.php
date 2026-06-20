<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open question the planning agent raised on a deliverable. Structured so the
 * plan-review gate can enforce "all answered before approve" (see
 * Deliverable::hasUnansweredQuestions). `answered_at` is stamped when an answer
 * is recorded.
 */
class DeliverableQuestion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep answered_at honest: stamp it the moment a non-empty answer lands,
        // clear it if the answer is removed.
        static::saving(function (DeliverableQuestion $q): void {
            if ($q->isDirty('answer')) {
                $q->answered_at = filled($q->answer) ? now() : null;
            }
        });
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
