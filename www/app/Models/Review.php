<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A review of a change against a base. The walkthrough the human reviews is its
 * ordered sections; an agent prepares it via MCP and hands back its URL.
 *
 * A review can cover many Tasks (and a task many reviews) and carries an
 * `assigned_to_user_id`: a human must atomically self-assign the review before
 * they may sign off its sections — the human mirror of an agent claiming a task.
 */
class Review extends Model
{
    protected $guarded = [];

    // ── Scope (what the review targets) ──────────────────────────────────────

    public const SCOPE_TASK = 'task';

    public const SCOPE_DELIVERABLE = 'deliverable';

    // ── Type (what kind of review) ───────────────────────────────────────────

    public const TYPE_FUNCTIONAL = 'functional';   // per task; behaviour/UX/UI/permissions

    public const TYPE_CODE = 'code';               // technical (task-level)

    public const TYPE_ARCHITECTURE = 'architecture'; // technical (deliverable-level)

    public const TYPE_PLAN = 'plan';               // a task's plan (Client-facing + Technical-architecture)

    /** Every review type the framework supports (drives create_review validation). */
    public const TYPES = [
        self::TYPE_FUNCTIONAL,
        self::TYPE_CODE,
        self::TYPE_ARCHITECTURE,
        self::TYPE_PLAN,
    ];

    protected function casts(): array
    {
        return [
            'plan_incomplete' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Is this a plan review (review_type = plan)? */
    public function isPlanReview(): bool
    {
        return $this->review_type === self::TYPE_PLAN;
    }

    /**
     * The two fixed sections a plan review always carries, in order: Client-facing
     * (renders the task `body`) and Technical-architecture (renders the `plan`).
     * `source` is the task field the section renders; it is read by the walkthrough
     * view and by the seeder when a plan review is created. A plan review has no
     * GitHub files, so coverage is trivially complete.
     *
     * @return list<array{title:string, source:string, context:string}>
     */
    public static function fixedPlanSections(): array
    {
        return [
            [
                'title' => 'Client-facing',
                'source' => 'body',
                'context' => 'What this task delivers in user terms (the task body). Approve when the scope is right; request changes (or raise a finding) on anything to clarify.',
            ],
            [
                'title' => 'Technical-architecture',
                'source' => 'plan',
                'context' => 'How it is built — the structure map (the task plan), written for a reviewer who has not read the code. Approve when the approach is sound; raise open questions as findings.',
            ],
        ];
    }

    /** The deliverable this review targets (scope = deliverable; else null). */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /** Is this a deliverable-scoped review (whole-deliverable diff vs base_branch)? */
    public function isDeliverableScoped(): bool
    {
        return $this->scope === self::SCOPE_DELIVERABLE;
    }

    /**
     * Auto-flag for re-review every section that covers one of $changedPaths
     * (the dirty-section watermark). Called when the comparison is refreshed so
     * the human only re-walks sections whose files actually changed; untouched
     * sections keep their prior decision. Returns the number of sections flagged.
     */
    public function flagStaleSections(array $changedPaths, string $note): int
    {
        if ($changedPaths === []) {
            return 0;
        }

        $sections = $this->sections()
            ->whereHas('files', fn ($q) => $q->whereIn('path', $changedPaths))
            ->get();

        foreach ($sections as $section) {
            $section->markStale($note);
        }

        return $sections->count();
    }

    /** The repository this review's comparison is within (null = doc-only review). */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasMany<ReviewSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ReviewSection::class)->orderBy('position');
    }

    /** The changed files of this review's comparison (GitHub order). */
    public function files(): HasMany
    {
        return $this->hasMany(ReviewFile::class)->orderBy('position');
    }

    /**
     * The coverage guard: every changed file must be allocated to at least one
     * section. Returns the totals + the still-uncovered file paths. A review with
     * no files (a doc-only review) is trivially complete.
     */
    public function coverage(): array
    {
        $files = $this->files()->withCount('sections')->get();
        $uncovered = $files->where('sections_count', 0)->pluck('path')->values()->all();

        return [
            'total' => $files->count(),
            'covered' => $files->count() - count($uncovered),
            'uncovered' => $uncovered,
            'complete' => $uncovered === [],
        ];
    }

    /** Convenience: is every changed file covered by a section? */
    public function isFullyCovered(): bool
    {
        return $this->coverage()['complete'];
    }

    /**
     * The human's verdict across sections: counts of approved / changes_requested
     * / undecided, and whether the review is ready to conclude (every section
     * decided). `verdict` is changes_requested if any section requests changes,
     * else approved when all are decided, else null.
     */
    public function decisionSummary(): array
    {
        $sections = $this->relationLoaded('sections') ? $this->sections : $this->sections()->get();
        $approved = $sections->where('decision', 'approved')->count();
        $changes = $sections->where('decision', 'changes_requested')->count();
        $total = $sections->count();
        $undecided = $total - $approved - $changes;

        return [
            'approved' => $approved,
            'changes_requested' => $changes,
            'undecided' => $undecided,
            'total' => $total,
            'all_decided' => $total > 0 && $undecided === 0,
            'verdict' => $changes > 0 ? 'changes_requested' : (($total > 0 && $undecided === 0) ? 'approved' : null),
        ];
    }

    /** The tasks this review covers (openable from either side). */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withTimestamps();
    }

    /** The human currently holding this review (null = unassigned). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Atomically claim this review for $userId, but only if it is currently
     * unassigned. Returns true if the claim succeeded (this caller now holds
     * it), false if someone else already holds it. The WHERE NULL guard makes
     * the check-and-set a single conditional UPDATE — no read-then-write race.
     */
    public function claimFor(int $userId): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->whereNull('assigned_to_user_id')
            ->update(['assigned_to_user_id' => $userId]) === 1;

        if ($claimed) {
            $this->assigned_to_user_id = $userId;
        }

        return $claimed;
    }

    /**
     * Release this review, but only if $userId currently holds it. Returns true
     * if it was released. Guarded the same way as the claim so a non-holder
     * can never clear another reviewer's assignment.
     */
    public function releaseFor(int $userId): bool
    {
        $released = static::whereKey($this->getKey())
            ->where('assigned_to_user_id', $userId)
            ->update(['assigned_to_user_id' => null]) === 1;

        if ($released) {
            $this->assigned_to_user_id = null;
        }

        return $released;
    }
}
