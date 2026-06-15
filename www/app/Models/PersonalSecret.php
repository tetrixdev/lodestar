<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own value for an env key, encrypted at rest. `project_id` null means
 * it applies to any project; a project-scoped row overrides the global one for
 * that project. Only ever delivered to the owning user via the out-of-MCP secrets
 * endpoint — never returned through an MCP tool.
 */
class PersonalSecret extends Model
{
    protected $guarded = [];

    protected $casts = ['value' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
