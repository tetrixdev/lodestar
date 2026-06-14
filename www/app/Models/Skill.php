<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned prompt that drives one phase of the agent loop. `kind=system`
 * skills are ours (read-only, shipped from code); `kind=user` skills are a
 * user's editable fork. See the migration for the column contract.
 */
class Skill extends Model
{
    protected $guarded = [];

    public const KIND_SYSTEM = 'system';

    public const KIND_USER = 'user';

    /**
     * The skill keys. The four lifecycle phases plus `main` — the bootstrap
     * skill an agent loads first (it has no task/working-state; it's fetched
     * explicitly via get_skill(phase:'main')).
     */
    public const PHASES = ['main', 'plan', 'develop', 'ai_review', 'merge'];

    public const PHASE_LABELS = [
        'main' => 'Main (bootstrap)',
        'plan' => 'Plan',
        'develop' => 'Develop',
        'ai_review' => 'AI review',
        'merge' => 'Merge & deploy',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystem(): bool
    {
        return $this->kind === self::KIND_SYSTEM;
    }

    /** The current (highest-version) system skill for a phase, or null. */
    public static function currentSystem(string $phase): ?self
    {
        return self::query()
            ->where('kind', self::KIND_SYSTEM)
            ->where('key', $phase)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Resolve which skill $user's loop should run for $phase on $project:
     * a project-specific binding, else the user's default binding, else the
     * current system skill. Returns null only if no system skill is seeded.
     */
    public static function resolve(User $user, ?Project $project, string $phase): ?self
    {
        $binding = SkillBinding::query()
            ->where('user_id', $user->id)
            ->where('phase', $phase)
            ->where(function ($q) use ($project) {
                $q->whereNull('project_id');
                if ($project) {
                    $q->orWhere('project_id', $project->id);
                }
            })
            // A project-specific binding (project_id NOT NULL) wins over the default.
            ->orderByRaw('project_id IS NULL')
            ->with('skill')
            ->first();

        return $binding?->skill ?? self::currentSystem($phase);
    }
}
