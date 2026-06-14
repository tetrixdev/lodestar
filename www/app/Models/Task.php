<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kanban card. `status` is the column (open | doing | done | cancelled);
 * `cancelled` is the soft-delete. `position` orders within a column.
 */
class Task extends Model
{
    protected $guarded = [];

    /** The live (non-cancelled) statuses, in board order. */
    public const COLUMNS = ['open', 'doing', 'done'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
