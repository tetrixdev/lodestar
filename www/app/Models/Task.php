<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        'is_corrective' => 'boolean',
        'needs_functional_review' => 'boolean',
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
        // Cancelled is a permanent archive — no restore. A card stays viewable
        // (and can always be recreated); it never re-enters the live pipeline.
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The plan-phase statuses. A task that belongs to a deliverable NEVER enters
     * these — planning happens once, at the deliverable level, and child tasks are
     * decomposed from the deliverable's approved plan. The `saving` hook coerces
     * any child found in one of these to the dev entry point, so it is structurally
     * impossible to persist a deliverable child in a plan phase.
     */
    public const PLANNING_PHASE_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READY_FOR_PLANNING,
        self::STATUS_PLANNING,
        self::STATUS_PLAN_REVIEW,
    ];

    /**
     * The lifecycle for a task that belongs to a deliverable: NO planning head
     * (it starts at ready_for_dev) and the human gate is the FUNCTIONAL review
     * (business/UX/UI/permissions), not code review — code/architecture review is
     * batched at the deliverable level. Same forward·back·cancel ordering.
     */
    public const TRANSITIONS_IN_DELIVERABLE = [
        self::STATUS_READY_FOR_DEV => [self::STATUS_DEVELOPING, self::STATUS_CANCELLED],
        self::STATUS_DEVELOPING => [self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_AI_REVIEW => [self::STATUS_AI_REVIEW, self::STATUS_DEVELOPING, self::STATUS_CANCELLED],
        self::STATUS_AI_REVIEW => [self::STATUS_HUMAN_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_HUMAN_REVIEW => [self::STATUS_APPROVED, self::STATUS_READY_FOR_AI_REVIEW, self::STATUS_READY_FOR_DEV, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_MERGE_DEPLOY, self::STATUS_HUMAN_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_MERGE_DEPLOY => [self::STATUS_DONE, self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_DONE => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /** The status a deliverable child is born into (planning already happened). */
    public const DELIVERABLE_CHILD_ENTRY = self::STATUS_READY_FOR_DEV;

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
     * The phase key (the playbook a working state runs) for each `*-ing` state.
     * `get_playbook` uses this to resolve which playbook a claimed task needs.
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

    /** The playbook phase key a `*-ing` task needs, or null if it isn't a working state. */
    public static function phaseFor(string $workingStatus): ?string
    {
        return self::PHASE_FOR_WORKING[$workingStatus] ?? null;
    }

    /** Transition kinds, keyed by target, so the UI can label/icon a control. */
    public const KIND_FORWARD = 'forward';

    public const KIND_BACK = 'back';

    public const KIND_CANCEL = 'cancel';

    protected static function booted(): void
    {
        // Stamp status_changed_at whenever the status actually changes (including
        // the very first save). Automatic so every code path — controller,
        // tinker, future agent loop — keeps the timer honest.
        static::saving(function (Task $task): void {
            // A deliverable child never lives in a plan-phase status — coerce it to
            // the dev entry point (covers both new children and tasks just attached
            // to a deliverable). Done before the timer check so the stamp is honest.
            if ($task->deliverable_id !== null && in_array($task->status, self::PLANNING_PHASE_STATUSES, true)) {
                $task->status = self::STATUS_READY_FOR_DEV;
            }

            if ($task->isDirty('status') || ! $task->exists) {
                $task->status_changed_at = now();
            }
        });

        // Assign the per-deliverable sub_id (1,2,3…) when a task is first attached
        // to a deliverable. Drives the nested branch name D{deliverable}/T{sub_id}.
        static::creating(function (Task $task): void {
            if ($task->deliverable_id !== null && $task->sub_id === null) {
                $task->sub_id = $task->deliverable->nextSubId();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The deliverable this task belongs to (null = standalone task). */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /**
     * The branch convention: a task under a deliverable nests its branch as
     * D{deliverable:06d}/T{sub_id:02d}-slug; a standalone task is flat
     * T{id:06d}-slug. See docs/deliverable-workflow.md §3.
     */
    public function branchName(): string
    {
        $slug = Str::slug($this->title);

        return $this->deliverable_id !== null
            ? sprintf('D%06d/T%02d-%s', $this->deliverable_id, (int) $this->sub_id, $slug)
            : sprintf('T%06d-%s', $this->id, $slug);
    }

    /** The reviews that cover this card (openable from the card), newest-first. */
    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class)
            ->withTimestamps()
            ->orderByDesc('reviews.created_at');
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

    /** Is this task a child of a deliverable (vs a standalone card)? */
    public function isDeliverableChild(): bool
    {
        return $this->deliverable_id !== null;
    }

    /**
     * The human-review gate for this task: a deliverable child's human review is
     * the FUNCTIONAL review (business/UX/UI); a standalone task's is the code review.
     */
    public function humanGateType(): string
    {
        return $this->isDeliverableChild() ? 'functional' : 'code';
    }

    /** The transition map that applies to this task (child vs standalone). */
    public function transitionMap(): array
    {
        return $this->isDeliverableChild() ? self::TRANSITIONS_IN_DELIVERABLE : self::TRANSITIONS;
    }

    /** The legal target statuses from this card's current status. */
    public function allowedTransitions(): array
    {
        return $this->transitionMap()[$this->status] ?? [];
    }

    /** Is moving this card to $target a legal transition? */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Classify a transition from this card's current status to $target. */
    public function transitionKind(string $target): string
    {
        if ($target === self::STATUS_CANCELLED) {
            return self::KIND_CANCEL;
        }

        $forward = $this->transitionMap()[$this->status][0] ?? null;

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
     * reach: the cards in a working (*-ing) state right now (with their project +
     * claiming agent, for the nav hover) and the most recent claim. Derived from
     * real activity, no ping. A `claimed_by` of "loop*" marks the work loop.
     *
     * @return array{working: int, tasks: Collection<int, Task>, last_claim: string|null}
     */
    public static function agentSnapshot(User $user): array
    {
        $base = self::query()->whereHas('project', fn ($q) => $q->accessibleBy($user));

        $tasks = (clone $base)
            ->whereIn('status', self::workingStatuses())
            ->with('project:id,name')
            ->get(['id', 'project_id', 'claimed_by', 'status']);

        return [
            'working' => $tasks->count(),
            'tasks' => $tasks,
            'last_claim' => (clone $base)->max('claimed_at'),
        ];
    }
}
