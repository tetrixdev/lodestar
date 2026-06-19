<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddFindingTool;
use App\Mcp\Tools\AdvanceDeliverableTool;
use App\Mcp\Tools\AdvanceTaskTool;
use App\Mcp\Tools\ClaimTaskTool;
use App\Mcp\Tools\ClaimWorkTool;
use App\Mcp\Tools\CreateReviewTool;
use App\Mcp\Tools\GetPlaybookTool;
use App\Mcp\Tools\GetReviewTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\LinkRepositoryTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ProposePlaybookChangeTool;
use App\Mcp\Tools\RememberTool;
use App\Mcp\Tools\ReportTool;
use App\Mcp\Tools\UnlinkRepositoryTool;
use App\Mcp\Tools\UpsertDeliverableTool;
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

Data tools read and write the board: upsert_project, upsert_task, upsert_deliverable,
get_task (read tasks by id), upsert_session, create_review (hands back a URL a
human opens), upsert_review_section, get_review.

A deliverable is the optional Project → Deliverable → Task layer: one goal, one
branch, one review funnel. Its child tasks skip planning (they're decomposed from
the deliverable's approved plan) and their human gate is the FUNCTIONAL review;
code/architecture review happens once, at the deliverable level.

Loop tools drive the lifecycle. claim_work atomically takes the next available unit
of work — a deliverable OR a task — and flips it to its working (*-ing) state; that
claim is the only way to start work. A child task is handed out only once its
deliverable is building and its dependencies are done. get_playbook returns the
prompt for a claimed task's or deliverable's phase. advance_task / advance_deliverable
move along LEGAL transitions only (the server rejects illegal jumps). report logs a
work-session. The human-only gates (plan_review, human_review, the deliverable's
human reviews) cannot be claimed — a human moves those in the web UI.

get_playbook composes a phase prompt across scopes (system → team → project →
personal). propose_playbook_change proposes a new version of a playbook layer; it is
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
        UpsertDeliverableTool::class,
        GetTaskTool::class,
        UpsertSessionTool::class,
        LinkRepositoryTool::class,
        UnlinkRepositoryTool::class,
        CreateReviewTool::class,
        UpsertReviewSectionTool::class,
        AddFindingTool::class,
        GetReviewTool::class,
        // Loop tools
        ClaimWorkTool::class,
        ClaimTaskTool::class,
        GetPlaybookTool::class,
        ProposePlaybookChangeTool::class,
        RememberTool::class,
        AdvanceTaskTool::class,
        AdvanceDeliverableTool::class,
        ReportTool::class,
    ];
}
