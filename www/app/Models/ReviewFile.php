<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One file in a review comparison, as reported by GitHub. The set of these
 * (across all of a review's comparisons) is the ground truth the coverage guard
 * checks section allocation against.
 */
class ReviewFile extends Model
{
    protected $guarded = [];

    /** The comparison (repo + base…head) this file changed within. */
    public function comparison(): BelongsTo
    {
        return $this->belongsTo(ReviewComparison::class, 'review_comparison_id');
    }

    /** The section(s) that cover this file (a file may be in several). */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(ReviewSection::class, 'review_file_section');
    }
}
