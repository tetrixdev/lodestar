<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A deliverable: the optional layer between a project and its tasks (Project →
 * Deliverable → Task). It owns a goal, an integration branch, and the review
 * funnel. The user creates it from a raw `concept`; the planning agent rewrites
 * it into `body` (our spec format), writes the `plan`, decomposes it into child
 * tasks, and raises open `questions`. Approved at `plan_review`, it cuts its
 * branch and its tasks build in parallel; when all are merged it runs a
 * deliverable-level AI review → human architecture review → human functional
 * sanity → merge.
 *
 * The lifecycle mirrors Task's design (STATUSES / TRANSITIONS / ACTORS / CLAIM_MAP)
 * so the board and its controls stay familiar. See docs/deliverable-workflow.md.
 */
class Deliverable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status_changed_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    // ── Statuses (the funnel) ────────────────────────────────────────────────

    public const STATUS_NEW = 'new';

    public const STATUS_READY_FOR_PLANNING = 'ready_for_planning';

    public const STATUS_PLANNING = 'planning';

    public const STATUS_PLAN_REVIEW = 'plan_review';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY_FOR_AI_REVIEW = 'ready_for_ai_review';

    public const STATUS_AI_REVIEW = 'ai_review';

    public const STATUS_HUMAN_ARCHITECTURE_REVIEW = 'human_architecture_review';

    public const STATUS_HUMAN_FUNCTIONAL_REVIEW = 'human_functional_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MERGE_DEPLOY = 'merge_deploy';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    /** Every status in funnel order (cancelled excluded — it is the archive). */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READY_FOR_PLANNING,
        self::STATUS_PLANNING,
        self::STATUS_PLAN_REVIEW,
        self::STATUS_BUILDING,
        self::STATUS_READY_FOR_AI_REVIEW,
        self::STATUS_AI_REVIEW,
        self::STATUS_HUMAN_ARCHITECTURE_REVIEW,
        self::STATUS_HUMAN_FUNCTIONAL_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_MERGE_DEPLOY,
        self::STATUS_DONE,
    ];

    /**
     * Which board phase column a deliverable card sits in, keyed to the same 5
     * phase keys as Task::PHASES so deliverable cards and task cards share the
     * unified board's columns.
     */
    public const PHASE_COLUMN = [
        self::STATUS_NEW => 'backlog',
        self::STATUS_READY_FOR_PLANNING => 'backlog',
        self::STATUS_PLANNING => 'plan',
        self::STATUS_PLAN_REVIEW => 'plan',
        self::STATUS_BUILDING => 'build',
        self::STATUS_READY_FOR_AI_REVIEW => 'review',
        self::STATUS_AI_REVIEW => 'review',
        self::STATUS_HUMAN_ARCHITECTURE_REVIEW => 'review',
        self::STATUS_HUMAN_FUNCTIONAL_REVIEW => 'review',
        self::STATUS_APPROVED => 'ship',
        self::STATUS_MERGE_DEPLOY => 'ship',
        self::STATUS_DONE => 'ship',
    ];

    /** The board phase column this deliverable's status belongs to. */
    public function phaseColumn(): string
    {
        return self::PHASE_COLUMN[$this->status] ?? 'backlog';
    }

    public const LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_READY_FOR_PLANNING => 'Ready for planning',
        self::STATUS_PLANNING => 'Planning',
        self::STATUS_PLAN_REVIEW => 'Plan review',
        self::STATUS_BUILDING => 'Building',
        self::STATUS_READY_FOR_AI_REVIEW => 'Ready for AI review',
        self::STATUS_AI_REVIEW => 'AI review',
        self::STATUS_HUMAN_ARCHITECTURE_REVIEW => 'Architecture review',
        self::STATUS_HUMAN_FUNCTIONAL_REVIEW => 'Functional sanity',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_MERGE_DEPLOY => 'Merge & deploy',
        self::STATUS_DONE => 'Done',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    // ── Actor categories (reuse Task's vocabulary) ───────────────────────────

    public const ACTORS = [
        self::STATUS_NEW => Task::ACTOR_NEEDS_HUMAN,
        self::STATUS_READY_FOR_PLANNING => Task::ACTOR_QUEUED,
        self::STATUS_PLANNING => Task::ACTOR_AI_WORKING,
        self::STATUS_PLAN_REVIEW => Task::ACTOR_NEEDS_HUMAN,
        self::STATUS_BUILDING => Task::ACTOR_AI_WORKING,
        self::STATUS_READY_FOR_AI_REVIEW => Task::ACTOR_QUEUED,
        self::STATUS_AI_REVIEW => Task::ACTOR_AI_WORKING,
        self::STATUS_HUMAN_ARCHITECTURE_REVIEW => Task::ACTOR_NEEDS_HUMAN,
        self::STATUS_HUMAN_FUNCTIONAL_REVIEW => Task::ACTOR_NEEDS_HUMAN,
        self::STATUS_APPROVED => Task::ACTOR_QUEUED,
        self::STATUS_MERGE_DEPLOY => Task::ACTOR_AI_WORKING,
        self::STATUS_DONE => Task::ACTOR_DONE,
        self::STATUS_CANCELLED => Task::ACTOR_ARCHIVED,
    ];

    // ── Legal transitions (the state machine) ────────────────────────────────

    /**
     * status => [allowed targets], ordered forward · back · cancel. Deliverable
     * review feedback (ai_review / architecture / functional) goes *back to
     * building*: the fix becomes a corrective task (no separate deliverable dev
     * cycle — see docs §4). `building → ready_for_ai_review` fires automatically
     * once no open child tasks remain.
     */
    public const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_PLANNING => [self::STATUS_PLANNING, self::STATUS_NEW, self::STATUS_CANCELLED],
        self::STATUS_PLANNING => [self::STATUS_PLAN_REVIEW, self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_PLAN_REVIEW => [self::STATUS_BUILDING, self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_BUILDING => [self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_AI_REVIEW => [self::STATUS_AI_REVIEW, self::STATUS_BUILDING, self::STATUS_CANCELLED],
        self::STATUS_AI_REVIEW => [self::STATUS_HUMAN_ARCHITECTURE_REVIEW, self::STATUS_BUILDING, self::STATUS_CANCELLED],
        self::STATUS_HUMAN_ARCHITECTURE_REVIEW => [self::STATUS_HUMAN_FUNCTIONAL_REVIEW, self::STATUS_BUILDING, self::STATUS_CANCELLED],
        self::STATUS_HUMAN_FUNCTIONAL_REVIEW => [self::STATUS_APPROVED, self::STATUS_BUILDING, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_MERGE_DEPLOY, self::STATUS_HUMAN_FUNCTIONAL_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_MERGE_DEPLOY => [self::STATUS_DONE, self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_DONE => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    // ── Claim map (the agent loop) ───────────────────────────────────────────

    /**
     * The deliverable-level queue→working claims an agent may take. `building` is
     * not here — during building it is the *child tasks* that are claimed, not the
     * deliverable. The human gates (plan_review, both human reviews) are absent by
     * construction, so the loop cannot grab them.
     */
    public const CLAIM_MAP = [
        self::STATUS_READY_FOR_PLANNING => self::STATUS_PLANNING,
        self::STATUS_READY_FOR_AI_REVIEW => self::STATUS_AI_REVIEW,
        self::STATUS_APPROVED => self::STATUS_MERGE_DEPLOY,
    ];

    /**
     * Playbook phase per working state. Reuses the existing phase keys for now
     * (the deliverable-specific *content* is a playbook concern handled via scope
     * / named keys later — see docs §10).
     */
    public const PHASE_FOR_WORKING = [
        self::STATUS_PLANNING => 'plan',
        self::STATUS_AI_REVIEW => 'ai_review',
        self::STATUS_MERGE_DEPLOY => 'merge',
    ];

    protected static function booted(): void
    {
        static::saving(function (Deliverable $deliverable): void {
            if ($deliverable->isDirty('status') || ! $deliverable->exists) {
                $deliverable->status_changed_at = now();
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sub_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(DeliverableQuestion::class)->orderBy('position');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // ── Lifecycle helpers (mirror Task) ──────────────────────────────────────

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public static function actorFor(string $status): string
    {
        return self::ACTORS[$status] ?? Task::ACTOR_NEEDS_HUMAN;
    }

    public static function phaseFor(string $workingStatus): ?string
    {
        return self::PHASE_FOR_WORKING[$workingStatus] ?? null;
    }

    /**
     * Classify a transition from this deliverable's current status to $target
     * (forward · back · cancel), reusing Task's KIND vocabulary so the shared
     * transition controls render identically.
     */
    public function transitionKind(string $target): string
    {
        if ($target === self::STATUS_CANCELLED) {
            return Task::KIND_CANCEL;
        }

        $forward = self::TRANSITIONS[$this->status][0] ?? null;

        return $target === $forward ? Task::KIND_FORWARD : Task::KIND_BACK;
    }

    // ── Open-questions gate ──────────────────────────────────────────────────

    /** Unanswered questions block approval at plan_review. */
    public function hasUnansweredQuestions(): bool
    {
        return $this->questions()->whereNull('answered_at')->exists();
    }

    /**
     * From plan_review the human may only proceed (→ building) once every question
     * is answered; otherwise the sole non-cancel move is back to planning.
     */
    public function canApprovePlan(): bool
    {
        return $this->status === self::STATUS_PLAN_REVIEW && ! $this->hasUnansweredQuestions();
    }

    // ── Child-task completion ────────────────────────────────────────────────

    /** Child tasks not yet done/cancelled — these keep the deliverable in building. */
    public function openTasks()
    {
        return $this->tasks()
            ->whereNotIn('status', [Task::STATUS_DONE, Task::STATUS_CANCELLED]);
    }

    /**
     * All child work merged? (building → ready_for_ai_review fires on this.) A
     * deliverable with no tasks is not "complete" — it has nothing to ship yet.
     */
    public function allTasksComplete(): bool
    {
        return $this->tasks()->exists() && ! $this->openTasks()->exists();
    }

    // ── Branch & sub-id helpers ──────────────────────────────────────────────

    /** The next per-deliverable task sub_id (1-based). */
    public function nextSubId(): int
    {
        return (int) $this->tasks()->max('sub_id') + 1;
    }

    /** The integration branch name: D{id:06d}-slug. */
    public function branchName(): string
    {
        return sprintf('D%06d-%s', $this->id, Str::slug($this->title));
    }
}
