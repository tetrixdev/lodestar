<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddFindingTool;
use App\Mcp\Tools\AdvanceTaskTool;
use App\Mcp\Tools\ClaimTaskTool;
use App\Mcp\Tools\CreateReviewTool;
use App\Mcp\Tools\GetReviewTool;
use App\Mcp\Tools\GetSkillTool;
use App\Mcp\Tools\LinkRepositoryTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ProposeSkillChangeTool;
use App\Mcp\Tools\RememberTool;
use App\Mcp\Tools\ReportTool;
use App\Mcp\Tools\UnlinkRepositoryTool;
use App\Mcp\Tools\UpsertProjectTool;
use App\Mcp\Tools\UpsertReviewSectionTool;
use App\Mcp\Tools\UpsertSessionTool;
use App\Mcp\Tools\UpsertTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Lodestar')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Lodestar is the control layer for AI-assisted software work. Every tool is scoped
to your token's user: you only ever see and write your own projects, tasks,
work-sessions and reviews.

Data tools read and write the board: upsert_project, upsert_task, upsert_session,
create_review (hands back a URL a human opens), upsert_review_section, get_review.

Loop tools drive the lifecycle. claim_task atomically takes the next queued
(ready_*) card and flips it to its working (*-ing) state — that claim is the only
way to start work. get_skill returns the prompt for a claimed task's phase.
advance_task moves a card along a LEGAL transition only (the server rejects
illegal jumps). report logs a work-session. The human-only gates (plan_review,
human_review) cannot be claimed — a human moves those in the web UI.

get_skill composes a phase prompt across scopes (system → team → project →
personal). propose_skill_change proposes a new version of a skill layer; it is
ALWAYS recorded as proposed and awaits a human approver — there is no approve
tool, by design.
TXT)]
class LodestarServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        // Data tools
        ListProjectsTool::class,
        UpsertProjectTool::class,
        UpsertTaskTool::class,
        UpsertSessionTool::class,
        LinkRepositoryTool::class,
        UnlinkRepositoryTool::class,
        CreateReviewTool::class,
        UpsertReviewSectionTool::class,
        AddFindingTool::class,
        GetReviewTool::class,
        // Loop tools
        ClaimTaskTool::class,
        GetSkillTool::class,
        ProposeSkillChangeTool::class,
        RememberTool::class,
        AdvanceTaskTool::class,
        ReportTool::class,
    ];
}
