<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The ONE source of truth for the body / plan / summary format a planning agent
 * writes into a task (and the deliverable scope). It is referenced from two
 * places that used to duplicate this prose and drift apart:
 *  - the `upsert_task` (and `upsert_deliverable`) MCP tool descriptions, and
 *  - the seeded `plan` / `develop` system playbooks (SystemPlaybookSeeder).
 *
 * Each constant is a short markdown fragment. Keep them terse — they are read
 * inline in tool schemas and composed into playbook prose. When the format
 * changes, change it HERE; the tool descriptions and the seeded playbooks both
 * pull from these constants, and TaskSpecSingleSourceTest fails if either side
 * stops referencing them.
 */
final class TaskSpec
{
    /** The client-facing `body`: what the change delivers, in user terms. */
    public const BODY =
        'The CLIENT-FACING description (markdown): what this delivers in user terms — '.
        '**Why** (the goal/problem), **What** (scope: in and out), **Done when** '.
        '(acceptance). No file-level detail (that goes in `plan`).';

    /** The `body_summary`: a short markdown blurb (~2–3 lines), not a flat sentence. */
    public const BODY_SUMMARY =
        'A SHORT markdown blurb (~2–3 lines) — the scannable TL;DR of the client-facing '.
        'description that the board card and review brief show. Markdown is rendered '.
        '(bold / lists / links), so write it to read well, not as one flat sentence.';

    /** The technical `plan`: the structure map, for a reviewer who has NOT read the code. */
    public const PLAN =
        'The TECHNICAL / ARCHITECTURE description (markdown): the structure map — files '.
        'added/moved/deleted (one plain line each), the flow, the conventions, and how it '.
        'is built. '.self::ARCHITECTURE_RULE;

    /** The `plan_summary`: a short markdown blurb (~2–3 lines). */
    public const PLAN_SUMMARY =
        'A SHORT markdown blurb (~2–3 lines) — the scannable TL;DR of the '.
        'technical/architecture description. Markdown is rendered, so write it to read '.
        'well, not as one flat sentence.';

    /**
     * The non-coder rule for the technical-architecture text. This is the single
     * place the rule is stated; both the tool `plan` description and the plan /
     * develop playbooks pull it from here.
     */
    public const ARCHITECTURE_RULE =
        'Write the Technical-architecture for a reviewer who has NOT read the code: stay '.
        'at the architecture level, NAME the new files you will add, and do NOT reference '.
        'existing files assuming the reader already knows their contents.';
}
