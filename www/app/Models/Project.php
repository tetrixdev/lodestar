<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project: a group of repos with a shared goal, owned by a user. The home base
 * for its tasks, work sessions and reviews.
 */
class Project extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['repos' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
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
}
