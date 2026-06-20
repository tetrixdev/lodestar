<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A project: a group of repos with a shared goal, owned by a user. The home base
 * for its tasks, work sessions and reviews.
 */
class Project extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** People explicitly on this project (beyond the team), with approval flags. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot('can_approve_prompts')
            ->withTimestamps();
    }

    /**
     * The access rule, in one place: a project is reachable by its owner, or by a
     * member of its team. Personal projects (team_id null) are owner-only, so this
     * is backward-compatible with the pre-teams `user_id` check.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $this->user_id === $user->id
            || ($this->team_id !== null && $user->isInTeam($this->team_id));
    }

    /** Scope a query to the projects a user can access (owned + their teams'). */
    public function scopeAccessibleBy($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereIn('team_id', $user->teamIds());
        });
    }

    /** May this user approve project-level playbook changes? (owner or an assigned approver) */
    public function canApprovePrompts(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }
        $member = $this->members()->where('users.id', $user->id)->first();

        return $member !== null && (bool) $member->pivot->can_approve_prompts;
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** The deliverables (the optional task-grouping layer) on this project. */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    /** The short chip label for the unified board — `code`, or a derived fallback. */
    public function chipCode(): string
    {
        if (filled($this->code)) {
            return $this->code;
        }

        // Derive: initials of the first two words, else the first 3 chars, upper-cased.
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return strtoupper(mb_substr((string) $this->name, 0, 3));
    }

    /** The chip colour for the board — `color`, or a stable default derived from the id. */
    public function chipColor(): string
    {
        if (filled($this->color)) {
            return $this->color;
        }

        // Stable hue from the id so each project keeps a consistent colour without config.
        $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];

        return $palette[$this->id % count($palette)];
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** The repositories (the "stack") this project spans. */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class, 'project_repository')->withTimestamps();
    }

    /** This project's project-scope playbook slots. */
    public function playbooks(): MorphMany
    {
        return $this->morphMany(Playbook::class, 'owner');
    }

    /** The env keys this project needs to run (the secrets manifest). */
    public function secretRequirements(): HasMany
    {
        return $this->hasMany(ProjectSecretRequirement::class);
    }

    /** Programs to install + commands to provide in this project's agent workspace. */
    public function tools(): HasMany
    {
        return $this->hasMany(ProjectTool::class);
    }
}
