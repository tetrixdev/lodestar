<?php

declare(strict_types=1);

namespace App\Models;

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
    protected $guarded = [];

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
