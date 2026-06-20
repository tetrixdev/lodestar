<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    /** The repo comparisons this review spans (one per repo; ordered). */
    public function comparisons(): HasMany
    {
        return $this->hasMany(ReviewComparison::class)->orderBy('position');
    }

    /** @return HasMany<ReviewSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ReviewSection::class)->orderBy('position');
    }

    /**
     * Every changed file across all this review's comparisons, in (comparison,
     * GitHub) order — the flat list the coverage guard and the file-tree use.
     */
    public function files(): HasManyThrough
    {
        return $this->hasManyThrough(ReviewFile::class, ReviewComparison::class)
            ->orderBy('review_comparisons.position')
            ->orderBy('review_files.position');
    }

    /** Does this review have at least one comparison (≥1 diff)? */
    public function hasComparison(): bool
    {
        return $this->comparisons()->exists();
    }

    /**
     * The coverage guard: every changed file (across all comparisons) must be
     * allocated to at least one section. Returns the totals + the still-uncovered
     * file paths. A review whose comparisons changed no files is trivially covered
     * — but a review still needs ≥1 comparison to reach a human (see hasComparison
     * / the human-review gate in AdvanceTaskTool).
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
