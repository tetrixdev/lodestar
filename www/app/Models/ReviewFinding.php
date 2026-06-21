<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Embeddable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding the AI-review raised within a section (scenario + impact), with a
 * severity and a human triage status.
 */
class ReviewFinding extends Model
{
    use Embeddable;

    protected $guarded = [];

    public const SEVERITIES = ['info', 'minor', 'major', 'critical'];

    public const STATUSES = ['open', 'must_fix', 'approved', 'dismissed'];

    public function embeddingText(): string
    {
        return trim(implode("\n", array_filter([
            $this->title,
            $this->detail,
        ])));
    }

    public function embeddingTenant(): array
    {
        $project = $this->section?->review?->project;

        return [
            'project_id' => $project?->id,
            'team_id' => $project?->team_id,
            'owner_user_id' => $project?->user_id,
            'is_system' => false,
            'scope' => 'project',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ReviewSection::class, 'review_section_id');
    }
}
