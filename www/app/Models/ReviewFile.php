<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One file in a review's comparison, as reported by GitHub. The set of these is
 * the ground truth the coverage guard checks section allocation against.
 */
class ReviewFile extends Model
{
    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** The section(s) that cover this file (a file may be in several). */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(ReviewSection::class, 'review_file_section');
    }
}
