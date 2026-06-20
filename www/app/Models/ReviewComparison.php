<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One repo comparison within a review (base_ref…head_ref in one repository). A
 * review may span several of these — one per repo — so a single human review can
 * walk a change that touches multiple repositories. Its `review_files` are the
 * authoritative changed-file set for this repo (fetched from GitHub, never the
 * AI's claim); the resolved `base_sha`/`head_sha` pin the blobs the file viewer
 * fetches even as the refs move.
 */
class ReviewComparison extends Model
{
    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** The repository this comparison runs within. */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** The changed files of this comparison (GitHub order). */
    public function files(): HasMany
    {
        return $this->hasMany(ReviewFile::class)->orderBy('position');
    }

    /** A human label for the comparison: "owner/repo  base…head". */
    public function label(): string
    {
        $refs = trim(($this->base_ref ?? '?').' … '.($this->head_ref ?? '?'));

        return $this->repository
            ? $this->repository->full_name.'  '.$refs
            : $refs;
    }
}
