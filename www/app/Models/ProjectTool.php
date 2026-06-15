<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tool a project's agent should have: a `program` to install/verify, or a
 * `command` (small script) to install into the workspace and call. See the
 * migration for the column contract.
 */
class ProjectTool extends Model
{
    protected $guarded = [];

    public const KIND_PROGRAM = 'program';

    public const KIND_COMMAND = 'command';

    public const KINDS = [self::KIND_PROGRAM, self::KIND_COMMAND];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
