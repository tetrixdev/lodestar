<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Captures EVERYTHING discussed across the build session as backlog cards, so no
 * idea is lost. Idempotent (firstOrCreate by title). Lodestar items land on the
 * "lodestar" project; the DungeonMeister merge (the work we came from) gets its
 * own project so it stays separate and pickup-able afterwards.
 */
class BacklogSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::orderBy('id')->first();
        if (! $user) {
            $this->command?->warn('No user — skipping.');

            return;
        }

        $lodestar = Project::where('slug', 'lodestar')->first();
        if (! $lodestar) {
            $this->command?->warn('No lodestar project — skipping.');

            return;
        }

        // ── Lodestar backlog: [category, title, body] (all land as `new`) ─────────
        $items = [
            // The real review pipeline (the immediate focus)
            ['review', 'Lodestar mirror docs — DATA-MODEL.md + ARCHITECTURE.md + a schema↔doc guard test',
                'Dogfood our own method on Lodestar: write the two mirror docs for its 7 tables + 2 pivots, and a test asserting DATA-MODEL matches the live schema. Mirror_guard review sections have nothing real to point at until these exist.'],
            ['review', 'Richer ReviewSection — AI finding + per-segment outcome (approve / changes-requested)',
                'A section needs more than open|signed_off: an AI-finding field, and an outcome the human picks per segment (approve, or changes-requested → back to dev or back to ai_review). This is what makes the human-review screen present the options we discussed, not a flat tick-box.'],
            ['review', 'AI-review pass that GENERATES a review from the actual change',
                'An agent reviews the real diff/change against the mirror docs and produces genuine per-surface findings, correct modes, and real file references — instead of a human hand-typing generic questions. The human review is then that, grounded.'],
            ['review', 'Human-review screen presents per-segment AI findings + decision options',
                'Per segment: show the AI finding, then offer approve / send back to AI review / send back to dev. The overall outcome drives the linked task transitions.'],
            ['review', 'Review outcome drives task lifecycle transitions',
                'All segments approved → linked tasks human_review → approved. Any send-back → tasks → ready_for_ai_review or ready_for_dev. Closes the AI-review ↔ human-review loop.'],
            ['review', 'Review sections must reference real files/docs (not PRs); guard against non-reviewables',
                'A review surface is a concrete built thing with a correct mode and a real file/doc link. Open questions/decisions are TASKS, not review sections (e.g. the DM-merge "section" was wrong).'],

            // MCP
            ['mcp', 'MCP server (HTTP/SSE, Sanctum-authed), multi-tenant scoped to the token user',
                'The agent-facing surface. Tool-agnostic; everything real rides MCP so the client stays thin.'],
            ['mcp', 'MCP data tools — upsert_project, upsert_task, upsert_session, create_review (returns URL), upsert_review_section, get_review', null],
            ['mcp', 'MCP loop tools — claim (ready_*→*-ing, atomic), get_skill, advance, report, heartbeat, check_version', null],
            ['mcp', 'get_skill / run-skill over MCP — deliver skills via MCP, not local files (so a skill edit updates every agent instantly)', null],

            // Loop / routines / distribution
            ['loop', 'Agent loop via Claude Code Routines + API-trigger (NOT an NPX poller)',
                'Lodestar fires a routine /fire endpoint the moment a task hits ready_* → event-driven, no minutely polling. Policy-clean: Routines bill from the subscription, not the metered programmatic pool (post-2026-06-15). /loop is session-bound; daily version-check is a scheduled routine. Batch at scale (one fire drains several queued tasks).'],
            ['loop', 'Atomic claim (FOR UPDATE SKIP LOCKED) + leases + reaper for tasks stuck in *-ing',
                'The ready_*→*-ing flip is the claim; a lease + scheduled reaper recovers a claimed task whose agent died.'],
            ['loop', 'AI gate on plan_review → ready_for_dev (AI blocks forward if the plan still has big gaps)', null],
            ['loop', 'Thin npx package (@tetrixdev/lodestar) — connect + run; ToolAdapter for tool-agnosticism',
                'connect = write MCP config + per-machine Sanctum token + create the routines; run = the loop adapter. Codex support becomes ~tiny because everything real rides MCP.'],
            ['loop', 'Self-update via check_version handshake (ok / nudge / required); plain signed npm',
                'Explicitly AGAINST "execute arbitrary release instructions" (supply-chain blast radius). The thin client rarely changes; almost everything ships server-side via MCP.'],

            // Skills
            ['skills', 'skills + skill_bindings tables; system skills (read-only, versioned, pushed on deploy) vs user skills', null],
            ['skills', 'Duplicate-to-customize a system skill; settings page binds each phase to system-or-fork',
                'Users keep control: swap which skill a flow-step uses; system updates still reach everyone, but a user on their fork keeps it (switches back to use ours).'],
            ['skills', 'Per-user setting — new system skills default enabled or disabled', null],
            ['skills', 'Author the plan / develop / ai-review / merge system skills (the flow skills)', null],

            // Teams / product (parking lot)
            ['teams', 'Teams — invite members, team membership', null],
            ['teams', 'Stakeholder types (technical vs business); user picks which; reviews show/ask different per type', null],
            ['teams', 'Review policies — need-all-stakeholders vs one-per-segment; approve when satisfied + notify the rest; default-assignee vs everyone-picks', null],
            ['product', 'White-label / self-host + hosted SaaS; pricing (pay to manage a team) — out of scope for v1', null],

            // Methodology
            ['methodology', 'Review/merge vps-setup PR #16 (the 5-mode review protocol + Laravel taxonomy) — awaiting operator',
                'https://github.com/tetrixdev/vps-setup/pull/16'],
            ['methodology', 'Migrate the review methodology OUT of vps-setup INTO Lodestar (skills + reviews); trim vps-setup back to provisioning', null],

            // UI / smaller
            ['ui', 'Per-task detail page (reviews currently link to the board; no per-task route yet)', null],
            ['ui', 'WorkSession UI — create/list/show session logs (model exists, no UI); capture this build session as the first one', null],

            // Infra
            ['infra', 'Harden dev env against Vite crash-on-edit (hot file vanishes → 500s; workaround = supervisorctl restart npm-dev)', null],
            ['infra', 'Reset operator account password (currently the temp verify-temp-pw-123)', null],
            ['infra', 'Clean up session scratch files (~/*.md, the vps-setup-work clone)', null],
        ];

        $created = 0;
        foreach ($items as [$cat, $title, $body]) {
            $task = Task::firstOrCreate(
                ['project_id' => $lodestar->id, 'title' => $title],
                ['category' => $cat, 'body' => $body, 'status' => Task::STATUS_NEW, 'position' => 0],
            );
            $created += $task->wasRecentlyCreated ? 1 : 0;
        }

        // ── DungeonMeister: its own project + the frozen merge to resume ──────────
        $dm = Project::firstOrCreate(
            ['user_id' => $user->id, 'slug' => 'dungeon-maister'],
            ['name' => 'DungeonMeister', 'primary_goal' => 'Land the game-engine branch on main and keep building the AI-DM engine.'],
        );
        Task::firstOrCreate(
            ['project_id' => $dm->id, 'title' => 'Resume the feat/game-engine-phase-1 → main merge (frozen at green)'],
            [
                'category' => 'merge',
                'status' => Task::STATUS_READY_FOR_PLANNING,
                'body' => 'Frozen mid-process while we built Lodestar. Plan + state: '
                    .'~/Repositories/_stacks/ai-bridge-stack/tasks/doing/dm-engine-merge-phase-1-to-main.md and '
                    .'~/Repositories/dungeon-maister/docs/REVIEW-game-engine-phase-1.md. Decided: merge STRAIGHT to main '
                    .'(delete feat/game-engine unmerged), no orphan schema (every approved table gets its AI writer first). '
                    .'Model queue done: relationship, campaign, plot, faction, character. Remaining models: location, '
                    .'session_log, dm_note, combat cluster, events. Then §C2 write-paths (faction memberships, K3 sweep, '
                    .'K1 bulk-load, xp awarding, K2 scope-TBD), architecture audit, cleanup, full suite + smoke, merge. '
                    .'Eventually drive THIS review through Lodestar (the dogfood).',
            ],
        );

        $this->command?->info("Backlog seeded: {$created} new Lodestar cards (idempotent); DungeonMeister project + resume task ensured.");
    }
}
