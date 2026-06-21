<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Embeddable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A work-session log entry — a running history of what was done on a project.
 * Named WorkSession (table `work_sessions`) to avoid clashing with the framework
 * session table / facade.
 */
class WorkSession extends Model
{
    use Embeddable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function embeddingText(): string
    {
        return trim(implode("\n", array_filter([
            $this->title,
            $this->body,
        ])));
    }

    public function embeddingTenant(): array
    {
        $project = $this->project;

        return [
            'project_id' => $this->project_id,
            'team_id' => $project?->team_id,
            'owner_user_id' => $project?->user_id,
            'is_system' => false,
            'scope' => 'project',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
