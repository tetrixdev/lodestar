<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Embeddable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a {@see Playbook} slot's prompt. A slot keeps its full history;
 * exactly one version is `active` (the one composition uses). A `proposed`
 * version awaits a human approver — approving it archives the prior active one.
 * `proposed_by_ai` records that an MCP client (an AI) authored the proposal,
 * which can never go live on its own.
 */
class PlaybookVersion extends Model
{
    use Embeddable;

    protected $guarded = [];

    public function embeddingText(): string
    {
        return trim(implode("\n", array_filter([
            $this->title,
            $this->summary,
            $this->body,
        ])));
    }

    /**
     * Tenancy flows from the owning slot's scope: a SYSTEM playbook is readable
     * by everyone (`is_system`); team/project/personal versions carry their
     * owner so the access filter can reach them.
     */
    public function embeddingTenant(): array
    {
        $slot = $this->playbook;

        return match ($slot?->scope) {
            Playbook::SCOPE_SYSTEM => ['is_system' => true, 'scope' => 'system'],
            Playbook::SCOPE_TEAM => ['team_id' => (int) $slot->owner_id, 'scope' => 'team', 'is_system' => false],
            Playbook::SCOPE_PROJECT => ['project_id' => (int) $slot->owner_id, 'scope' => 'project', 'is_system' => false],
            Playbook::SCOPE_PERSONAL => ['owner_user_id' => (int) $slot->owner_id, 'scope' => 'personal', 'is_system' => false],
            default => ['is_system' => false, 'scope' => null],
        };
    }

    protected $casts = [
        'proposed_by_ai' => 'boolean',
        'version' => 'integer',
    ];

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PROPOSED,
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
        self::STATUS_REJECTED,
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** The work-session this version was proposed from (provenance), if any. */
    public function workSession(): BelongsTo
    {
        return $this->belongsTo(WorkSession::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isProposed(): bool
    {
        return $this->status === self::STATUS_PROPOSED;
    }

    /**
     * A stable content fingerprint of this version — the compare-and-swap token
     * a writer must echo back to prove they read the current state before editing
     * (see {@see Playbook::currentHash()}). Covers every field a reader would see.
     */
    public function contentHash(): string
    {
        return substr(hash('sha256', implode("\x00", [
            (string) $this->version,
            (string) $this->status,
            (string) $this->title,
            (string) $this->summary,
            (string) $this->mode,
            (string) $this->body,
        ])), 0, 16);
    }
}
