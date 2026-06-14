<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A review of a change against a base. The walkthrough the human reviews is its
 * ordered sections; an agent prepares it via MCP and hands back its URL.
 *
 * A review can cover many Tasks (and a task many reviews) and carries an
 * `assigned_to_user_id`: a human must atomically self-assign the review before
 * they may sign off its sections — the human mirror of an agent claiming a task.
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

    /** The tasks this review covers (openable from either side). */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withTimestamps();
    }

    /** The human currently holding this review (null = unassigned). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Atomically claim this review for $userId, but only if it is currently
     * unassigned. Returns true if the claim succeeded (this caller now holds
     * it), false if someone else already holds it. The WHERE NULL guard makes
     * the check-and-set a single conditional UPDATE — no read-then-write race.
     */
    public function claimFor(int $userId): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->whereNull('assigned_to_user_id')
            ->update(['assigned_to_user_id' => $userId]) === 1;

        if ($claimed) {
            $this->assigned_to_user_id = $userId;
        }

        return $claimed;
    }

    /**
     * Release this review, but only if $userId currently holds it. Returns true
     * if it was released. Guarded the same way as the claim so a non-holder
     * can never clear another reviewer's assignment.
     */
    public function releaseFor(int $userId): bool
    {
        $released = static::whereKey($this->getKey())
            ->where('assigned_to_user_id', $userId)
            ->update(['assigned_to_user_id' => null]) === 1;

        if ($released) {
            $this->assigned_to_user_id = null;
        }

        return $released;
    }
}
