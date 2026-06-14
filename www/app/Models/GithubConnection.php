<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A linked GitHub account/token. The token is encrypted at rest. Repositories
 * are read through the connection they belong to, so the right token is always
 * used for the right repo (work vs personal accounts).
 */
class GithubConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
