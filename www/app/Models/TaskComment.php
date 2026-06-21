<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Embeddable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An async note on a task — from a human or an agent. */
class TaskComment extends Model
{
    use Embeddable;

    protected $guarded = [];

    public function embeddingText(): string
    {
        return trim((string) $this->body);
    }

    public function embeddingTenant(): array
    {
        $project = $this->task?->project;

        return [
            'project_id' => $project?->id,
            'team_id' => $project?->team_id,
            'owner_user_id' => $project?->user_id,
            'is_system' => false,
            'scope' => 'project',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
