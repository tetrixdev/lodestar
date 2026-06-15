<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team that shares projects + a managed skill set. The owner is always also a
 * member (added on create) with prompt-approval rights.
 */
class Team extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allow_personal_instructions' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The owner is always a member with approval rights.
        static::created(function (Team $team): void {
            $team->members()->syncWithoutDetaching([
                $team->owner_user_id => ['role' => 'owner', 'can_approve_prompts' => true],
            ]);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot(['role', 'can_approve_prompts'])
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** May this user approve team-level skill changes? */
    public function canApprovePrompts(User $user): bool
    {
        $member = $this->members()->where('users.id', $user->id)->first();

        return $member !== null && (bool) $member->pivot->can_approve_prompts;
    }
}
