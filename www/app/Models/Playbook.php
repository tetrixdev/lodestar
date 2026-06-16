<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A playbook SLOT: one addressable layer of a composed prompt, identified by
 * (scope, owner, key). The prompt text lives in versioned {@see PlaybookVersion}
 * rows — exactly one is `active`, and that's the body composition uses.
 *
 * For a phase key, the effective prompt is COMPOSED across scopes in order
 * system → team → project → personal (see {@see self::compose()}): each layer
 * `append`s by default, or `overwrite`s (discarding everything above it and
 * starting from its own body). Personal is LAST so a person always has the
 * final say (and can test changes), unless the team locks it out via
 * `allow_personal_instructions = false`. Arbitrary named keys do not compose —
 * they resolve to the most-specific scope only (see {@see self::resolveNamed()}).
 */
class Playbook extends Model
{
    protected $guarded = [];

    // ── Scopes (the layer an owner contributes) ──────────────────────────────

    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_PROJECT = 'project';

    /** Scopes in composition order: system base → team → project → personal. */
    public const SCOPES = [self::SCOPE_SYSTEM, self::SCOPE_TEAM, self::SCOPE_PROJECT, self::SCOPE_PERSONAL];

    // ── Compose mode ─────────────────────────────────────────────────────────

    public const MODE_APPEND = 'append';

    public const MODE_OVERWRITE = 'overwrite';

    public const MODES = [self::MODE_APPEND, self::MODE_OVERWRITE];

    // ── Phase keys (these compose; any other key is a named playbook) ────────────

    /**
     * The composing phase keys. `main` is the bootstrap playbook an agent loads
     * first; the other four drive one lifecycle phase each.
     */
    public const PHASES = ['main', 'plan', 'develop', 'ai_review', 'merge'];

    public const PHASE_LABELS = [
        'main' => 'Main (bootstrap)',
        'plan' => 'Plan',
        'develop' => 'Develop',
        'ai_review' => 'AI review',
        'merge' => 'Merge & deploy',
    ];

    /** A phase key composes across scopes; any other key is a named playbook. */
    public static function isPhase(string $key): bool
    {
        return in_array($key, self::PHASES, true);
    }

    /**
     * Keys with this prefix are reserved for Lodestar's own (system-seeded)
     * playbooks — users can't create them, so we never collide with future
     * internal keys. Users may still layer on phase keys (that's composition).
     */
    public const RESERVED_KEY_PREFIX = 'ls_';

    public static function isReservedKey(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_KEY_PREFIX);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** The owner of the slot: a Team, User (personal) or Project. Null = system. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlaybookVersion::class);
    }

    /** The single live version this slot contributes. */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(PlaybookVersion::class)->where('status', PlaybookVersion::STATUS_ACTIVE);
    }

    /** Proposed (awaiting human approval) versions, newest first. */
    public function proposedVersions(): HasMany
    {
        return $this->versions()->where('status', PlaybookVersion::STATUS_PROPOSED)->latest('version');
    }

    /** The newest version on this slot by version number (any status), or null. */
    public function latestVersion(): ?PlaybookVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }

    /**
     * The compare-and-swap token a writer must echo to prove they read current
     * state before editing: the latest version's content fingerprint, or a stable
     * "empty slot" token when nothing exists yet (so even a first proposal is a
     * deliberate act, not a blind write). See {@see propose()} / {@see publish()}.
     */
    public function currentHash(): string
    {
        // The latest version of ANY status — so every write (proposal or publish)
        // bumps the token: after you write, the old base_hash is stale and a second
        // blind write is refused until you re-read. A slot with no versions yet gets
        // a stable "empty" token so even a first write is a deliberate act.
        $latest = $this->latestVersion();
        if ($latest !== null) {
            return $latest->contentHash();
        }

        return substr(hash('sha256', 'empty:'.$this->scope.':'.$this->key.':'.($this->owner_id ?? 'null')), 0, 16);
    }

    /**
     * Guard a write against a stale read: if the caller supplied a base hash and
     * the slot has moved on since, refuse — they must re-read. A null hash skips
     * the check (trusted callers like the seeder).
     *
     * @throws \App\Exceptions\StalePlaybookHashException
     */
    public function assertFreshHash(?string $expectedHash): void
    {
        if ($expectedHash === null) {
            return;
        }
        $current = $this->currentHash();
        if (! hash_equals($current, $expectedHash)) {
            throw new \App\Exceptions\StalePlaybookHashException($this, $expectedHash, $current, $this->latestVersion());
        }
    }

    public function isSystem(): bool
    {
        return $this->scope === self::SCOPE_SYSTEM;
    }

    // ── Slot lookup ───────────────────────────────────────────────────────────

    /**
     * The slot for a (scope, owner, key), with its active version eager-loaded.
     * $owner is null for the system scope.
     */
    public static function slotFor(string $scope, ?Model $owner, string $key): ?self
    {
        $query = self::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->with('activeVersion');

        if ($owner === null) {
            $query->whereNull('owner_id');
        } else {
            $query->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey());
        }

        return $query->first();
    }

    // ── Composition (phase keys) ──────────────────────────────────────────────

    /**
     * The effective prompt for a phase $key, composed for $user working on
     * $project. Layers apply in order system → team → project → personal; an
     * `overwrite` layer discards everything accumulated above it. Personal is
     * last so a person always has the final say, unless the project's team
     * forbids personal instructions (then the personal layer is dropped).
     *
     * @return array{body: string, layers: list<array{scope: string, title: string, mode: string, version: int, playbook_id: int, hash: string}>}
     */
    public static function compose(User $user, ?Project $project, string $key): array
    {
        $team = $project?->team;
        $allowPersonal = $team === null ? true : (bool) $team->allow_personal_instructions;

        // The candidate slot at each scope, in composition order.
        $candidates = [self::slotFor(self::SCOPE_SYSTEM, null, $key)];
        if ($team !== null) {
            $candidates[] = self::slotFor(self::SCOPE_TEAM, $team, $key);
        }
        if ($project !== null) {
            $candidates[] = self::slotFor(self::SCOPE_PROJECT, $project, $key);
        }
        if ($allowPersonal) {
            $candidates[] = self::slotFor(self::SCOPE_PERSONAL, $user, $key);
        }

        $bodies = [];
        $layers = [];

        foreach (array_filter($candidates) as $slot) {
            $version = $slot->activeVersion;
            if ($version === null) {
                continue; // a slot with no active version contributes nothing
            }

            if ($version->mode === self::MODE_OVERWRITE) {
                $bodies = [];   // discard everything above this layer
                $layers = [];
            }

            $bodies[] = $version->body;
            $layers[] = [
                'scope' => $slot->scope,
                'title' => $version->title,
                'mode' => $version->mode,
                'version' => $version->version,
                'playbook_id' => $slot->id,
                'hash' => $slot->currentHash(), // CAS token to propose against this layer
            ];
        }

        $body = implode("\n\n", $bodies);

        // The bootstrap playbook advertises the named, on-demand playbooks in scope so
        // the agent knows what it can pull via get_playbook(key:...).
        if ($key === 'main') {
            $catalog = self::namedCatalog($user, $project);
            if ($catalog !== []) {
                $lines = array_map(fn (array $c) => "- `{$c['key']}` — {$c['summary']}", $catalog);
                $body .= "\n\n## Available playbooks (load on demand with get_playbook key:<key>)\n".implode("\n", $lines);
            }
        }

        return ['body' => $body, 'layers' => $layers];
    }

    /**
     * The named (non-phase) playbooks reachable for $user on $project, each resolved
     * to its most-specific scope, as [key, summary] rows for the `main` catalog.
     *
     * @return list<array{key: string, summary: string}>
     */
    public static function namedCatalog(User $user, ?Project $project): array
    {
        $team = $project?->team;

        $slots = self::query()
            ->whereNotIn('key', self::PHASES)
            ->with('activeVersion')
            ->whereHas('versions', fn ($q) => $q->where('status', PlaybookVersion::STATUS_ACTIVE))
            ->where(function ($q) use ($user, $project, $team) {
                $q->where(fn ($q) => $q->where('scope', self::SCOPE_SYSTEM)->whereNull('owner_id'))
                    ->orWhere(fn ($q) => $q->where('scope', self::SCOPE_PERSONAL)
                        ->where('owner_type', User::class)->where('owner_id', $user->id));
                if ($team) {
                    $q->orWhere(fn ($q) => $q->where('scope', self::SCOPE_TEAM)
                        ->where('owner_type', Team::class)->where('owner_id', $team->id));
                }
                if ($project) {
                    $q->orWhere(fn ($q) => $q->where('scope', self::SCOPE_PROJECT)
                        ->where('owner_type', Project::class)->where('owner_id', $project->id));
                }
            })
            ->get();

        // Keep the most-specific scope per key (project > personal > team > system).
        $rank = [self::SCOPE_PROJECT => 0, self::SCOPE_PERSONAL => 1, self::SCOPE_TEAM => 2, self::SCOPE_SYSTEM => 3];
        $byKey = [];
        foreach ($slots as $slot) {
            if (! isset($byKey[$slot->key]) || $rank[$slot->scope] < $rank[$byKey[$slot->key]->scope]) {
                $byKey[$slot->key] = $slot;
            }
        }

        return collect($byKey)
            ->sortKeys()
            ->map(fn (self $s) => ['key' => $s->key, 'summary' => $s->activeVersion?->summary ?: $s->activeVersion?->title ?: $s->key])
            ->values()
            ->all();
    }

    // ── Authorization (change control) ───────────────────────────────────────

    /**
     * May $user *propose* a change to this slot? Anyone who can reach the scope:
     * the personal owner, a team member, a project member. System slots are
     * seeded, never authored here.
     */
    public function canBeAccessedBy(User $user): bool
    {
        return match ($this->scope) {
            self::SCOPE_PERSONAL => (int) $this->owner_id === $user->id,
            self::SCOPE_TEAM => $user->isInTeam((int) $this->owner_id),
            self::SCOPE_PROJECT => (bool) $this->owner?->isAccessibleBy($user),
            default => false,
        };
    }

    /**
     * May $user *approve* (make live) a change to this slot? The personal owner
     * for their own, an assigned approver for team/project. Approval is always a
     * human — the MCP never calls this.
     */
    public function canBeApprovedBy(User $user): bool
    {
        return match ($this->scope) {
            self::SCOPE_PERSONAL => (int) $this->owner_id === $user->id,
            self::SCOPE_TEAM, self::SCOPE_PROJECT => (bool) $this->owner?->canApprovePrompts($user),
            default => false,
        };
    }

    /** Find or create the slot for (scope, owner, key); never the system scope. */
    public static function ensureSlot(string $scope, ?Model $owner, string $key, string $title): self
    {
        return self::firstOrCreate(
            [
                'scope' => $scope,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getKey(),
                'key' => $key,
            ],
            ['title' => $title],
        );
    }

    // ── Versioning ─────────────────────────────────────────────────────────

    /** The next version number for this slot (1-based, monotonic). */
    public function nextVersion(): int
    {
        return (int) $this->versions()->max('version') + 1;
    }

    /**
     * Record a proposed change to this slot — awaiting a human approver. Used by
     * anyone who can access the scope but cannot approve it, and by every MCP
     * (AI) proposal regardless of who owns it.
     */
    public function propose(string $title, ?string $summary, string $body, ?User $author, bool $byAi, ?string $note = null, ?int $workSessionId = null, string $mode = self::MODE_APPEND, ?string $expectedHash = null): PlaybookVersion
    {
        $this->assertFreshHash($expectedHash);

        return $this->versions()->create([
            'version' => $this->nextVersion(),
            'title' => $title,
            'summary' => $summary,
            'mode' => $mode,
            'body' => $body,
            'status' => PlaybookVersion::STATUS_PROPOSED,
            'author_user_id' => $author?->id,
            'proposed_by_ai' => $byAi,
            'note' => $note,
            'work_session_id' => $workSessionId,
        ]);
    }

    /**
     * Make $version the live one, archiving whatever was active before it. The
     * version must belong to this slot. Returns it for chaining.
     */
    public function activate(PlaybookVersion $version): PlaybookVersion
    {
        $this->versions()
            ->where('status', PlaybookVersion::STATUS_ACTIVE)
            ->whereKeyNot($version->id)
            ->update(['status' => PlaybookVersion::STATUS_ARCHIVED]);

        $version->update(['status' => PlaybookVersion::STATUS_ACTIVE]);
        $this->unsetRelation('activeVersion');

        return $version;
    }

    /**
     * The change-control rule, in one place: an AI proposal (`$byAi`) NEVER goes
     * live — even on the author's own slot. A human who can approve the scope
     * self-approves to `active`; anyone else's change lands `proposed` for an
     * approver. Used identically by the web form and the MCP tool.
     */
    public function submitVersion(string $title, ?string $summary, string $body, User $author, bool $byAi, ?string $note = null, string $mode = self::MODE_APPEND, ?string $expectedHash = null): PlaybookVersion
    {
        if (! $byAi && $this->canBeApprovedBy($author)) {
            return $this->publish($title, $summary, $body, $author, mode: $mode, expectedHash: $expectedHash);
        }

        return $this->propose($title, $summary, $body, $author, $byAi, $note, mode: $mode, expectedHash: $expectedHash);
    }

    /**
     * Publish a brand-new active version in one step (proposal that lands live
     * immediately): a self-approved human edit, or the system seeder. Archives
     * the prior active version.
     */
    public function publish(string $title, ?string $summary, string $body, ?User $author = null, bool $byAi = false, string $mode = self::MODE_APPEND, ?string $expectedHash = null): PlaybookVersion
    {
        $this->assertFreshHash($expectedHash);

        $version = $this->versions()->create([
            'version' => $this->nextVersion(),
            'title' => $title,
            'summary' => $summary,
            'mode' => $mode,
            'body' => $body,
            'status' => PlaybookVersion::STATUS_ACTIVE,
            'author_user_id' => $author?->id,
            'proposed_by_ai' => $byAi,
        ]);

        return $this->activate($version);
    }

    /**
     * A named (non-phase) playbook resolves to the MOST-SPECIFIC scope only — no
     * composition: personal → project → team → system (personal wins, mirroring
     * its final say in composition, unless the team locks personal out). Returns
     * the active version, or null if no scope defines that key.
     */
    public static function resolveNamed(User $user, ?Project $project, string $key): ?PlaybookVersion
    {
        $team = $project?->team;
        $allowPersonal = $team === null ? true : (bool) $team->allow_personal_instructions;

        // (scope, owner) candidates, most-specific first.
        $candidates = [];
        if ($allowPersonal) {
            $candidates[] = [self::SCOPE_PERSONAL, $user];
        }
        if ($project !== null) {
            $candidates[] = [self::SCOPE_PROJECT, $project];
        }
        if ($team !== null) {
            $candidates[] = [self::SCOPE_TEAM, $team];
        }
        $candidates[] = [self::SCOPE_SYSTEM, null];

        foreach ($candidates as [$scope, $owner]) {
            $slot = self::slotFor($scope, $owner, $key);
            if ($slot?->activeVersion !== null) {
                return $slot->activeVersion;
            }
        }

        return null;
    }
}
