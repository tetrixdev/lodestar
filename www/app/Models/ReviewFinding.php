<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding the AI-review raised within a section (scenario + impact), with a
 * severity and a human triage status.
 */
class ReviewFinding extends Model
{
    protected $guarded = [];

    public const SEVERITIES = ['info', 'minor', 'major', 'critical'];

    public const STATUSES = ['open', 'must_fix', 'approved', 'dismissed'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(ReviewSection::class, 'review_section_id');
    }
}
