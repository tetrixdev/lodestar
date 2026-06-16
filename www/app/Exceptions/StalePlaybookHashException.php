<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Playbook;
use App\Models\PlaybookVersion;
use RuntimeException;

/**
 * Thrown when a playbook write supplies a base hash that no longer matches the
 * slot's current state — i.e. the writer is editing from a stale read. The
 * caller (web or MCP) catches it and hands the current content + hash back so
 * the writer re-reads before retrying. This is the compare-and-swap that forces
 * "read the current version before you propose a new one".
 */
class StalePlaybookHashException extends RuntimeException
{
    public function __construct(
        public readonly Playbook $playbook,
        public readonly string $expected,
        public readonly string $current,
        public readonly ?PlaybookVersion $latest,
    ) {
        parent::__construct(
            "This playbook changed since you read it (you sent base_hash={$expected}, current is {$current}). "
            .'Re-read the current version, then propose against the current hash.'
        );
    }
}
