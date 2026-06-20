<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered step of a plan-review walkthrough — the plan-side mirror of a
 * ReviewSection. Where a code-review section covers part of a diff, a
 * plan-review section covers part of the planning agent's `plan`: `focus` names
 * the slice of the plan (e.g. "Data model", "Migration", "MCP surface"),
 * `context` rebuilds the reviewer's knowledge, and `checks` is the "what to
 * confirm" list. `status` carries the human's per-section sign-off and
 * `decision` their approve / changes_requested verdict (distinct from sign-off,
 * exactly as on a ReviewSection).
 */
class PlanReviewSection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
