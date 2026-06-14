<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub repository ("owner/name") read through one GitHub connection, linked
 * to one or more projects. Its connection's token is what Lodestar uses to read
 * the compare API for this repo.
 */
class Repository extends Model
{
    protected $guarded = [];

    // NB: named githubConnection(), not connection() — `$model->connection`
    // collides with Eloquent's built-in DB-connection-name property.
    public function githubConnection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class, 'github_connection_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_repository')->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** The token (from this repo's connection) used to read it on GitHub. */
    public function token(): ?string
    {
        return $this->githubConnection?->token;
    }
}
