<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kanban card moving through the Lodestar lifecycle.
 *
 * `status` is one of the 13 lifecycle states (see STATUSES). The board groups
 * them into 5 phase columns (see PHASES) and colours each by who it is waiting
 * on (see ACTORS). `cancelled` is the archive (soft-delete). `position` orders
 * cards within a single status. `status_changed_at` records when the card last
 * entered its current status and drives the "Nh in <status>" timer; it is
 * stamped automatically whenever `status` changes (see the `saving` hook).
 */
class Task extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status_changed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'start_date' => 'date',
        'due_date' => 'date',
    ];

    // ── Priority ─────────────────────────────────────────────────────────────

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    /** Priorities, low→urgent. */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    /** Sort rank (higher = more urgent) so a column can order urgent-first. */
    public const PRIORITY_RANK = [
        self::PRIORITY_LOW => 0,
        self::PRIORITY_NORMAL => 1,
        self::PRIORITY_HIGH => 2,
        self::PRIORITY_URGENT => 3,
    ];

    public function priorityRank(): int
    {
        return self::PRIORITY_RANK[$this->priority] ?? 1;
    }

    /** Overdue = a due date in the past and not yet done/cancelled. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->status, [self::STATUS_DONE, self::STATUS_CANCELLED], true);
    }

    // ── The 13 statuses ──────────────────────────────────────────────────────

    public const STATUS_NEW = 'new';

    public const STATUS_READY_FOR_PLANNING = 'ready_for_planning';

    public const STATUS_PLANNING = 'planning';

    public const STATUS_PLAN_REVIEW = 'plan_review';

    public const STATUS_READY_FOR_DEV = 'ready_for_dev';

    public const STATUS_DEVELOPING = 'developing';

    public const STATUS_READY_FOR_AI_REVIEW = 'ready_for_ai_review';

    public const STATUS_AI_REVIEW = 'ai_review';

    public const STATUS_HUMAN_REVIEW = 'human_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MERGE_DEPLOY = 'merge_deploy';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    /** Every lifecycle status, in pipeline order (cancelled excluded — it's the archive). */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READY_FOR_PLANNING,
        self::STATUS_PLANNING,
        self::STATUS_PLAN_REVIEW,
        self::STATUS_READY_FOR_DEV,
        self::STATUS_DEVELOPING,
        self::STATUS_READY_FOR_AI_REVIEW,
        self::STATUS_AI_REVIEW,
        self::STATUS_HUMAN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_MERGE_DEPLOY,
        self::STATUS_DONE,
    ];

    /**
     * The live statuses shown on the board (every status except the archive).
     * Kept for backward compatibility with callers that referenced COLUMNS as
     * "the statuses on the board".
     */
    public const COLUMNS = self::STATUSES;

    // ── Actor categories (who the card is waiting on) ────────────────────────

    public const ACTOR_NEEDS_HUMAN = 'needs-human';

    public const ACTOR_QUEUED = 'queued';

    public const ACTOR_AI_WORKING = 'ai-working';

    public const ACTOR_DONE = 'done';

    public const ACTOR_ARCHIVED = 'archived';

    /** status => actor category. */
    public const ACTORS = [
        self::STATUS_NEW => self::ACTOR_NEEDS_HUMAN,
        self::STATUS_READY_FOR_PLANNING => self::ACTOR_QUEUED,
        self::STATUS_PLANNING => self::ACTOR_AI_WORKING,
        self::STATUS_PLAN_REVIEW => self::ACTOR_NEEDS_HUMAN,
        self::STATUS_READY_FOR_DEV => self::ACTOR_QUEUED,
        self::STATUS_DEVELOPING => self::ACTOR_AI_WORKING,
        self::STATUS_READY_FOR_AI_REVIEW => self::ACTOR_QUEUED,
        self::STATUS_AI_REVIEW => self::ACTOR_AI_WORKING,
        self::STATUS_HUMAN_REVIEW => self::ACTOR_NEEDS_HUMAN,
        self::STATUS_APPROVED => self::ACTOR_QUEUED,
        self::STATUS_MERGE_DEPLOY => self::ACTOR_AI_WORKING,
        self::STATUS_DONE => self::ACTOR_DONE,
        self::STATUS_CANCELLED => self::ACTOR_ARCHIVED,
    ];

    // ── Board phases (the 5 columns) ─────────────────────────────────────────

    /** phase key => [label, [statuses in phase order]]. */
    public const PHASES = [
        'backlog' => [
            'label' => 'Backlog',
            'statuses' => [self::STATUS_NEW, self::STATUS_READY_FOR_PLANNING],
        ],
        'plan' => [
            'label' => 'Plan',
            'statuses' => [self::STATUS_PLANNING, self::STATUS_PLAN_REVIEW],
        ],
        'build' => [
            'label' => 'Build',
            'statuses' => [self::STATUS_READY_FOR_DEV, self::STATUS_DEVELOPING],
        ],
        'review' => [
            'label' => 'Review',
            'statuses' => [self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_AI_REVIEW, self::STATUS_HUMAN_REVIEW],
        ],
        'ship' => [
            'label' => 'Ship',
            'statuses' => [self::STATUS_APPROVED, self::STATUS_MERGE_DEPLOY, self::STATUS_DONE],
        ],
    ];

    /** Human-readable label per status (for badges). */
    public const LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_READY_FOR_PLANNING => 'Ready for planning',
        self::STATUS_PLANNING => 'Planning',
        self::STATUS_PLAN_REVIEW => 'Plan review',
        self::STATUS_READY_FOR_DEV => 'Ready for dev',
        self::STATUS_DEVELOPING => 'Developing',
        self::STATUS_READY_FOR_AI_REVIEW => 'Ready for AI review',
        self::STATUS_AI_REVIEW => 'AI review',
        self::STATUS_HUMAN_REVIEW => 'Human review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_MERGE_DEPLOY => 'Merge & deploy',
        self::STATUS_DONE => 'Done',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    // ── Legal transitions (the state machine) ────────────────────────────────

    /**
     * status => [allowed target statuses]. Order within each list is
     * forward · back · cancel, which the UI relies on for control ordering.
     */
    public const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_PLANNING => [self::STATUS_PLANNING, self::STATUS_NEW, self::STATUS_CANCELLED],
        self::STATUS_PLANNING => [self::STATUS_PLAN_REVIEW, self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_PLAN_REVIEW => [self::STATUS_READY_FOR_DEV, self::STATUS_READY_FOR_PLANNING, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_DEV => [self::STATUS_DEVELOPING, self::STATUS_PLAN_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_DEVELOPING => [self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_AI_REVIEW => [self::STATUS_AI_REVIEW, self::STATUS_DEVELOPING, self::STATUS_CANCELLED],
        self::STATUS_AI_REVIEW => [self::STATUS_HUMAN_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_HUMAN_REVIEW => [self::STATUS_APPROVED, self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_MERGE_DEPLOY, self::STATUS_HUMAN_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_MERGE_DEPLOY => [self::STATUS_DONE, self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_DONE => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [self::STATUS_NEW],
    ];

    // ── Claim map (the agent loop) ───────────────────────────────────────────

    /**
     * The atomic claim a loop performs: a `ready_*` queue state flips to the
     * `*-ing` working state the claiming agent then holds. These are the only
     * four states an agent may claim — the human-only gates (`plan_review`,
     * `human_review`) are absent by construction, so the loop literally cannot
     * grab them.
     */
    public const CLAIM_MAP = [
        self::STATUS_READY_FOR_PLANNING => self::STATUS_PLANNING,
        self::STATUS_READY_FOR_DEV => self::STATUS_DEVELOPING,
        self::STATUS_READY_FOR_AI_REVIEW => self::STATUS_AI_REVIEW,
        self::STATUS_APPROVED => self::STATUS_MERGE_DEPLOY,
    ];

    /**
     * The phase key (the skill a working state runs) for each `*-ing` state.
     * `get_skill` uses this to resolve which skill a claimed task needs.
     */
    public const PHASE_FOR_WORKING = [
        self::STATUS_PLANNING => 'plan',
        self::STATUS_DEVELOPING => 'develop',
        self::STATUS_AI_REVIEW => 'ai_review',
        self::STATUS_MERGE_DEPLOY => 'merge',
    ];

    /** The `ready_*` states an agent may claim. */
    public static function claimableStatuses(): array
    {
        return array_keys(self::CLAIM_MAP);
    }

    /** The queue state a `*-ing` task returns to when released (inverse of CLAIM_MAP). */
    public static function queueStateFor(string $workingStatus): ?string
    {
        return array_search($workingStatus, self::CLAIM_MAP, true) ?: null;
    }

    /** The skill phase key a `*-ing` task needs, or null if it isn't a working state. */
    public static function phaseFor(string $workingStatus): ?string
    {
        return self::PHASE_FOR_WORKING[$workingStatus] ?? null;
    }

    /** Transition kinds, keyed by target, so the UI can label/icon a control. */
    public const KIND_FORWARD = 'forward';

    public const KIND_BACK = 'back';

    public const KIND_CANCEL = 'cancel';

    public const KIND_RESTORE = 'restore';

    protected static function booted(): void
    {
        // Stamp status_changed_at whenever the status actually changes (including
        // the very first save). Automatic so every code path — controller,
        // tinker, future agent loop — keeps the timer honest.
        static::saving(function (Task $task): void {
            if ($task->isDirty('status') || ! $task->exists) {
                $task->status_changed_at = now();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The reviews that cover this card (openable from the card). */
    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class)->withTimestamps();
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->oldest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->latest();
    }

    /** Tasks this one is blocked by. */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id')->withTimestamps();
    }

    /** Tasks blocked by this one. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id')->withTimestamps();
    }

    /** Blocked = has an unfinished dependency. */
    public function isBlocked(): bool
    {
        return $this->dependencies()
            ->whereNotIn('status', [self::STATUS_DONE, self::STATUS_CANCELLED])
            ->exists();
    }

    /** Append an entry to the activity log. */
    public function logEvent(string $type, ?string $actor = null, ?string $description = null): void
    {
        $this->events()->create(['type' => $type, 'actor' => $actor, 'description' => $description]);
    }

    /** The legal target statuses from this card's current status. */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    /** Is moving this card to $target a legal transition? */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Classify a transition from this card's current status to $target. */
    public function transitionKind(string $target): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::KIND_RESTORE;
        }
        if ($target === self::STATUS_CANCELLED) {
            return self::KIND_CANCEL;
        }

        $forward = self::TRANSITIONS[$this->status][0] ?? null;

        return $target === $forward ? self::KIND_FORWARD : self::KIND_BACK;
    }

    /** The actor category of a given status. */
    public static function actorFor(string $status): string
    {
        return self::ACTORS[$status] ?? self::ACTOR_NEEDS_HUMAN;
    }

    /** The "*-ing" (AI actively working) statuses. */
    public static function workingStatuses(): array
    {
        return array_keys(array_filter(
            self::ACTORS,
            fn (string $actor) => $actor === self::ACTOR_AI_WORKING,
        ));
    }

    /**
     * A lightweight "is an agent active?" snapshot across the projects $user can
     * reach: how many cards are in a working (*-ing) state right now, and the most
     * recent claim. Drives the nav heartbeat — derived from real activity, no ping.
     *
     * @return array{working: int, last_claim: string|null}
     */
    public static function agentSnapshot(User $user): array
    {
        $base = self::query()->whereHas('project', fn ($q) => $q->accessibleBy($user));

        return [
            'working' => (clone $base)->whereIn('status', self::workingStatuses())->count(),
            'last_claim' => (clone $base)->max('claimed_at'),
        ];
    }
}
