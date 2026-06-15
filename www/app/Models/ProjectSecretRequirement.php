<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key a project needs in its environment to run — the manifest entry. Not
 * sensitive (just a name + description); the VALUE is a {@see PersonalSecret}
 * each user provides for themselves.
 */
class ProjectSecretRequirement extends Model
{
    protected $guarded = [];

    protected $casts = ['is_secret' => 'boolean'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
