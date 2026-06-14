<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use App\Models\Task;
use Illuminate\Database\Seeder;

/**
 * Builds the comprehensive "session 1" review walkthrough in Lodestar — the
 * operator uses /reviews/1 as the single guidance screen to review everything
 * built today. Idempotent: re-running refreshes the review + its sections.
 */
class SessionReviewSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::where('slug', 'lodestar')->first();
        if (! $project) {
            $this->command?->warn('No "lodestar" project found — skipping.');

            return;
        }

        // Capture the two new requirements raised this session as Backlog cards.
        foreach ([
            ['app', 'Routine setup flow + local connect command (npx connect → create the daily version-check + work-pickup routines and wire Lodestar to fire them)'],
            ['app', 'Routine health view — per-routine last-fired time + orphaned-trigger detection (a fire that was never followed up)'],
        ] as [$cat, $title]) {
            Task::firstOrCreate(
                ['project_id' => $project->id, 'title' => $title],
                ['category' => $cat, 'status' => Task::STATUS_NEW, 'position' => 0],
            );
        }

        $review = Review::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Lodestar — build review (session 1)'],
            [
                'base_ref' => 'empty repo',
                'head_ref' => 'lodestar slice',
                'status' => 'in_review',
                'intro' => 'Assume you remember nothing — you have not read the running commentary, and that is fine. '
                    .'This walks the whole of today top-to-bottom; each section rebuilds the context it needs before '
                    .'asking you to confirm or decide. Tick a section once you are happy with it; add a comment to send '
                    .'anything back. The goal: by the end you have seen everything that mattered, in order, without '
                    .'reading a line of code.',
            ],
        );

        // Replace sections so re-runs stay clean.
        $review->sections()->delete();

        $sections = [
            [
                'mode' => 'direct',
                'title' => '1 · What Lodestar is, and why we are building it',
                'context' => 'We were landing the big DungeonMeister engine branch via a "review the structure, not the '
                    .'lines" method. That method kept growing and did not fit in the vps-setup server config. So you '
                    .'decided to build THIS app — Lodestar — as the home for it: projects, tasks, work sessions, reviews '
                    .'and skills, driven by humans through a web UI and by AI agents through MCP, tool-agnostic. Its first '
                    .'real job is to review the DungeonMeister merge through itself (dogfood).',
                'link' => '/projects/1',
                'checks' => [
                    'Does this framing still match what you want Lodestar to be?',
                    'Anything to redirect before we build further on top of it?',
                ],
            ],
            [
                'mode' => 'mirror_guard',
                'title' => '2 · The review method behind this very screen',
                'context' => 'Every reviewable surface gets one of five modes: Skip · Behavioural (observe it run) · '
                    .'Direct read (enumerated so you know what to read) · Direct + companion doc + agent-verification '
                    .'(for things with no automated test, e.g. ARCHITECTURE) · Mirror + guard (a doc with a test proving '
                    .'it matches reality). The iron rule: an unguarded claim is a hope — the guard ships with the mirror. '
                    .'This is codified in a vps-setup pull request, and migrates INTO Lodestar over time.',
                'link' => 'https://github.com/tetrixdev/vps-setup/pull/16',
                'checks' => [
                    'Approve vps-setup PR #16 (the generic protocol)? — it is waiting on your review.',
                    'Agree the methodology should live in Lodestar (skills + reviews) long-term, not vps-setup?',
                ],
            ],
            [
                'mode' => 'mirror_guard',
                'title' => '3 · The data model (the nouns)',
                'context' => 'Five tables, multi-tenant by user ownership: Project (repos + a goal), Task (a kanban card '
                    .'with the lifecycle status), WorkSession (a work-log entry), Review and ReviewSection (a change '
                    .'reviewed against a base; the sections are the data behind this walkthrough). No skills tables yet — '
                    .'those come with the orchestration work.',
                'link' => '/projects/1',
                'checks' => [
                    'Do the nouns match what you have in mind?',
                    'Anything to cut or rename before we lean on it?',
                ],
            ],
            [
                'mode' => 'behavioural',
                'title' => '4 · The task lifecycle — your state machine',
                'context' => 'The 13-state flow you designed: new → ready_for_planning → planning → plan_review → '
                    .'ready_for_dev → developing → ready_for_ai_review → ai_review → human_review → approved → '
                    .'merge_deploy → done (+ cancelled). "ready_*" = a queue the agent loop claims; "*-ing" = an agent is '
                    .'actively on it (no double-pickup); plan_review and human_review are human-only gates the loop cannot '
                    .'claim. Built into the board: 5 phase columns, a collapsed "AI working" drawer per column, '
                    .'colour by who-it-waits-on (amber=you, blue=queued, violet pulse=AI, green=done), a "Nh in status" '
                    .'timer, and legal-transition-only move buttons.',
                'link' => '/projects/1',
                'checks' => [
                    'Open the board and walk it — does the built flow match what you described?',
                    'Try a few transitions (forward / back / cancel). Do the legal moves feel right?',
                    'Any state, gate, or transition missing or wrong?',
                ],
            ],
            [
                'mode' => 'behavioural',
                'title' => '5 · The review walkthrough (this screen)',
                'context' => 'You are using it now. This is the artifact you review changes WITH: ordered sections that '
                    .'rebuild context as you go, each with a comment box and a sign-off that persists to the database '
                    .'(progress bar + a "ready" banner when all are signed off). Eventually an agent prepares one of '
                    .'these via MCP and hands you back its URL.',
                'link' => '#',
                'checks' => [
                    'Does the rebuild-context-as-you-go + per-section sign-off work as your review tool?',
                    'Tick a section and reload — confirm the sign-off persists.',
                    'What would make this screen better for real reviews?',
                ],
            ],
            [
                'mode' => 'direct_doc',
                'title' => '6 · The orchestration architecture (loop · distribution · skills · updates)',
                'context' => 'A plan was drafted (file ~/lodestar-architecture-plan.md, sent to you): a deliberately dumb '
                    .'client with all policy server-side; distribution via a thin npx package; skills as system-vs-user '
                    .'with settings to swap them, delivered over MCP so an edit updates every agent instantly; self-update '
                    .'via a version handshake. KEY UPDATE from research: the loop should be Claude Code ROUTINES + an API '
                    .'trigger — Lodestar fires a routine the moment a task hits ready_*, so it is event-driven (no '
                    .'minutely polling) and policy-clean (Routines bill from your subscription, NOT the metered '
                    .'programmatic pool that the 2026-06-15 change introduced). /loop is session-bound; the daily '
                    .'version-check is a scheduled routine. You have confirmed: assume Routines for now.',
                'link' => '#',
                'checks' => [
                    'Confirm: Routines + API-trigger as the loop direction (not an NPX poller).',
                    'The 6 operator-only decisions at the end of the architecture plan — those are your calls.',
                ],
            ],
            [
                'mode' => 'direct',
                'title' => '7 · New requirements you raised — captured',
                'context' => 'You added two: (a) a setup FLOW + a local command a user runs to create the routines we '
                    .'need and connect them to Lodestar; (b) a routine HEALTH view in the app — for each routine, when it '
                    .'last fired, and whether a trigger fired without being followed up. Both are now Backlog cards on the '
                    .'board.',
                'link' => '/projects/1',
                'checks' => [
                    'Confirm both are captured correctly (see the Backlog column).',
                    'Where do they sit in priority vs the MCP server and driving the DungeonMeister review?',
                ],
            ],
            [
                'mode' => 'direct',
                'title' => '8 · DungeonMeister merge — frozen, waiting on Lodestar',
                'context' => 'DungeonMeister is frozen at a green state; the agreed plan is to merge it STRAIGHT to main '
                    .'via this review method (no orphan schema — every approved table gets its AI writer first). It is the '
                    .'first real customer for Lodestar review tool once MCP lands. It has not moved while we built Lodestar.',
                'link' => null,
                'checks' => [
                    'After the Lodestar slice (MCP), is driving the DungeonMeister review through Lodestar the next priority?',
                    'Or keep building Lodestar (skills / routines / health) further first?',
                ],
            ],
        ];

        foreach ($sections as $i => $s) {
            ReviewSection::create([
                'review_id' => $review->id,
                'position' => $i,
                'title' => $s['title'],
                'mode' => $s['mode'],
                'context' => $s['context'],
                'link' => $s['link'],
                'checks' => $s['checks'],
                'status' => 'open',
            ]);
        }

        $this->command?->info("Session review #{$review->id} seeded with ".count($sections).' sections.');
    }
}
