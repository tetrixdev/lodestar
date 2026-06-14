<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use App\Models\Task;
use Illuminate\Database\Seeder;

/**
 * Builds the grounded first-version self-review of Lodestar — the operator walks
 * /reviews/2 top-to-bottom to review everything that's actually built, against
 * the mirror docs and the real code. Every section is a CONCRETELY-built surface
 * with the correct review mode, a real file/doc/screen reference, and the AI's
 * actual finding (this seeder is written from a real read of the code). Idempotent
 * on the review title, so re-running refreshes the review + its sections in place.
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

        $review = Review::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Lodestar — build review (session 1)'],
            [
                'base_ref' => 'empty repo',
                'head_ref' => 'lodestar v1',
                'status' => 'in_review',
                'intro' => 'Assume you remember nothing — and that is fine. This walks the BUILT slice of Lodestar '
                    .'top-to-bottom against its mirror docs and real code; every section names exactly what to open and '
                    .'what the AI already checked, then asks you to confirm. Tick a section when you are happy; add a '
                    .'comment to send anything back. Open questions and not-yet-built ideas are backlog cards, not '
                    .'sections here — every section below reviews something concretely built.',
            ],
        );

        // Replace sections so re-runs stay clean.
        $review->sections()->delete();

        $sections = [
            [
                'mode' => 'mirror_guard',
                'title' => '1 · The data model — does the doc mirror the schema?',
                'context' => 'Lodestar now has the two mirror docs our own method demands. DATA-MODEL.md describes the '
                    .'nouns in business language: 5 tables (Project, Task, WorkSession, Review, ReviewSection) + the '
                    .'review_task pivot, all multi-tenant by ownership (a Project belongs to a User; everything else '
                    .'reaches its owner through the Project). A drift-guard test parses the doc\'s Mermaid ER diagram and '
                    .'asserts it matches the LIVE schema column-for-column. I ran the parser against the real schema: all '
                    .'7 boxes (5 tables + pivot + users) match exactly — no missing or phantom columns. One caveat I want '
                    .'you to know: docs/ lives at the repo root and is NOT mounted into the container, so the guard '
                    .'markTestSkipped()s in-container today (it does NOT red the suite) — mounting ./docs makes it run.',
                'link' => 'docs/DATA-MODEL.md',
                'checks' => [
                    'Skim DATA-MODEL.md — do the 5 nouns + pivot, and the Invariants, match what you have in mind?',
                    'OK that the schema-guard ships now but only runs once we mount docs/ into the container/CI?',
                    'Anything to cut or rename before we lean on this model?',
                ],
            ],
            [
                'mode' => 'direct_doc',
                'title' => '2 · The architecture — components, flows, and the one planned Boundary',
                'context' => 'ARCHITECTURE.md mirrors what is BUILT: the controllers (Project/Task/Review), the lifecycle '
                    .'state machine + board, the review walkthrough + atomic assignment, and multi-tenancy by ownership — '
                    .'with two Mermaid flows. Crucially it is honest that Lodestar has NO live external boundary today '
                    .'(no LLM call, no third-party API, no generated query); the only Boundary is the PLANNED MCP / '
                    .'agent-loop surface, written up as a gap + placeholder, not as running code. I checked the doc '
                    .'against the code: the components, the legal-only transitions, and the WHERE-NULL claim it describes '
                    .'all match what is actually in app/. There is no automated test for prose architecture, so this '
                    .'section is direct-read + my verification — you are signing off that the doc is true.',
                'link' => 'docs/ARCHITECTURE.md',
                'checks' => [
                    'Read ARCHITECTURE.md — does it describe the system you think we built, in the right altitude?',
                    'Agree the MCP/loop surface belongs as a *planned Boundary* (gap), not yet a mirror entry?',
                ],
            ],
            [
                'mode' => 'direct',
                'title' => '3 · The lifecycle state machine — the rules in Task.php',
                'context' => 'The 13-state flow lives as constants + small helpers on one model: app/Models/Task.php. I '
                    .'read it end-to-end and cross-checked the pieces: the 12 live STATUSES (+ cancelled) each appear in '
                    .'exactly one of the 5 PHASES, and each has an ACTOR (needs-human / queued / ai-working / done / '
                    .'archived) and a LABEL — a test (TaskLifecycleTest) asserts that completeness. TRANSITIONS encodes '
                    .'forward · back · cancel per state (cancelled restores to new), and canTransitionTo() / '
                    .'transitionKind() drive the per-card buttons from it. The saving() hook stamps status_changed_at '
                    .'whenever status is dirty (or on first save), so the "Nh in status" timer stays honest on every code '
                    .'path. Finding: the state machine is internally consistent and is the single source of truth the '
                    .'controller and the board both read.',
                'link' => 'app/Models/Task.php',
                'checks' => [
                    'Is the 13-state set, the 5-phase grouping, and the forward/back/cancel map exactly what you want?',
                    'Are plan_review and human_review the right human-only gates (no agent-claim)?',
                ],
            ],
            [
                'mode' => 'behavioural',
                'title' => '4 · The board — walk the lifecycle in the real UI',
                'context' => 'The board (ProjectController::show + projects/show.blade) renders the lifecycle: live cards '
                    .'grouped into the 5 phase columns, archived (cancelled) cards aside, a category filter, per-card '
                    .'colour-by-actor, the "Nh in status" timer, and legal-transition-only move buttons. Moves go through '
                    .'TaskController::update() which REJECTS any illegal transition (422 / validation error), while drag '
                    .'within a column goes through move() which only reorders (never changes status) and ignores spoofed '
                    .'or cross-project ids. BoardTest covers all of this (legal move stamps the timer, illegal is 422, '
                    .'cancel/restore round-trips, reorder is owner-scoped). This one is behavioural — please drive it.',
                'link' => '/projects/1',
                'checks' => [
                    'Open the board and move a card forward, back, and to cancel — do the legal moves feel right?',
                    'Confirm an illegal jump is simply not offered (the buttons only show legal targets).',
                    'Does the colour-by-actor + time-in-status read clearly at a glance?',
                ],
            ],
            [
                'mode' => 'direct',
                'title' => '5 · The review system — atomic assignment + task links',
                'context' => 'The review system is two models + one controller. Review::claimFor() self-assigns via a '
                    .'single conditional UPDATE guarded on WHERE assigned_to_user_id IS NULL — an atomic check-and-set, so '
                    .'two humans can never both hold a review; releaseFor() is guarded symmetrically (WHERE holder = me), '
                    .'so a non-holder can never clear someone else\'s claim. ReviewController::updateSection() RE-CHECKS '
                    .'the hold on every sign-off (403 otherwise), and tenancy is checked in every method (project owner '
                    .'or 403). A review covers many tasks via the review_task pivot; the walkthrough lists them and each '
                    .'board card links back. ReviewAssignmentTest proves the atomicity (second claim no-ops), the gate, '
                    .'and the two-way link. Finding: the concurrency-sensitive bits are done at the DB level, not in PHP '
                    .'read-then-write — the right place.',
                'link' => 'app/Models/Review.php',
                'checks' => [
                    'Agree the claim/release primitive (atomic WHERE-NULL UPDATE) is the right server-side guard?',
                    'Is "must hold the review to sign off a section" the gate you want?',
                ],
            ],
            [
                'mode' => 'behavioural',
                'title' => '6 · The review walkthrough — this very screen, and its sign-off gate',
                'context' => 'You are using it now (reviews/show.blade + ReviewController). Ordered sections rebuild '
                    .'context as you descend; each has a comment box and a sign-off that persists via fetch → '
                    .'updateSection(); a progress bar tracks signed-off/total and a banner appears when all are signed '
                    .'off. The whole thing is locked read-only until you "Assign to me" (the assignee chip up top), '
                    .'mirroring an agent claiming a task. This review IS the dogfood: it reviews Lodestar through '
                    .'Lodestar. Behavioural — exercise the gate live.',
                'link' => '/reviews/2',
                'checks' => [
                    'Before assigning: confirm the sections are read-only (note + tick disabled).',
                    'Assign to yourself, tick this section, reload — confirm the sign-off persisted.',
                    'Does rebuild-context-as-you-go + per-section sign-off work as your real review tool?',
                ],
            ],
            [
                'mode' => 'direct',
                'title' => '7 · Multi-tenancy & auth — is every surface scoped to you?',
                'context' => 'Lodestar is multi-tenant by ownership with NO row-level user_id below Project — ownership is '
                    .'reached through project.user_id. I checked every project-scoped controller method: ProjectController '
                    .'(index scopes to request->user()->projects(); show aborts 403 unless owner), TaskController (store/'
                    .'update/move all abort 403 unless the task\'s project is yours; move additionally filters ids to the '
                    .'project + status), ReviewController (index/show/assign/unassign/updateSection all abort 403 unless '
                    .'the review\'s project owner). Routes are all behind auth middleware. Tests assert intruders get 403 '
                    .'on transitions, reorder, assignment, and view. Finding: I found no project-scoped path that skips '
                    .'the ownership check.',
                'link' => 'app/Http/Controllers/ReviewController.php',
                'checks' => [
                    'Agree ownership-through-Project (no per-row user_id) is the tenancy model you want?',
                    'Any surface you expected to be shareable across users (it is strictly single-owner today)?',
                ],
            ],
            [
                'mode' => 'behavioural',
                'title' => '8 · The test suite — what is actually guarded',
                'context' => 'The suite is green (52 tests). It covers the load-bearing behaviour: the lifecycle '
                    .'completeness + legal-transition map + status_changed_at stamping (TaskLifecycleTest), the board\'s '
                    .'move/transition/reorder/tenancy paths (BoardTest), and the review link + atomic claim/release + '
                    .'sign-off gate (ReviewAssignmentTest), plus the Breeze auth tests. The new schema drift-guard '
                    .'(SchemaMirrorTest) ships too — it skips cleanly in-container (docs/ not mounted) and will enforce '
                    .'DATA-MODEL.md once mounted. Run: docker exec lodestar-dev-php php artisan test.',
                'link' => 'tests/Feature/SchemaMirrorTest.php',
                'checks' => [
                    'Comfortable that the guarded behaviour above is the right set for v1?',
                    'Want the schema-guard wired to actually RUN (mount docs/) before we build further on the model?',
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

        // Link this review to the four cards currently sitting in human_review.
        // Idempotent: match by title, then sync() so a re-run replaces the set.
        $taskIds = $project->tasks()
            ->where('status', Task::STATUS_HUMAN_REVIEW)
            ->whereIn('title', [
                'Scaffold app — Laravel 13 + slim-docker + Breeze (Tailwind v4)',
                'Data models — Project / Task / WorkSession / Review / ReviewSection',
                'Kanban board UI',
                'Review walkthrough page (served demonstrator)',
            ])
            ->pluck('id');
        $review->tasks()->sync($taskIds);

        $this->command?->info("Session review #{$review->id} seeded with ".count($sections)
            .' sections and '.$taskIds->count().' linked tasks.');
    }
}
