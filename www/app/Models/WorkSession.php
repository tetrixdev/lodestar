<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A work-session log entry — a running history of what was done on a project.
 * Named WorkSession (table `work_sessions`) to avoid clashing with the framework
 * session table / facade.
 */
class WorkSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
