<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A review of a change against a base. The walkthrough the human reviews is its
 * ordered sections; an agent prepares it via MCP and hands back its URL.
 */
class Review extends Model
{
    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ReviewSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ReviewSection::class)->orderBy('position');
    }
}
