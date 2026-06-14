<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

/**
 * The four system skills that ship with Lodestar — the prompts that drive each
 * phase of the loop. Idempotent by (kind, key, version): re-running upserts the
 * current version in place, so this is safe to call on every deploy. Bump the
 * version constant when a skill's contract changes and you want both the old and
 * new to coexist (forks pin to a version; unbound users follow the newest).
 */
class SystemSkillSeeder extends Seeder
{
    private const VERSION = 1;

    public function run(): void
    {
        foreach ($this->skills() as $key => [$title, $body]) {
            Skill::updateOrCreate(
                ['kind' => Skill::KIND_SYSTEM, 'key' => $key, 'version' => self::VERSION],
                ['title' => $title, 'body' => $body, 'user_id' => null, 'source_version' => null],
            );
        }
    }

    /** @return array<string, array{0:string,1:string}> */
    private function skills(): array
    {
        return [
            'plan' => ['Plan a task', <<<'MD'
                You are planning a single Lodestar task. Read the task body and the
                project's docs (DATA-MODEL.md, ARCHITECTURE.md, CONVENTIONS.md) first.

                Produce a **structure map**, not code: every file the change will add,
                move, or delete — one plain line each — plus the flow and which
                conventions apply. If the diff would be too big to review comfortably,
                split the task and say so. Call out any structural/product decision the
                human must make.

                When the plan is ready, advance the task to `plan_review` for a human.
                Do not write code in this phase.
                MD],

            'develop' => ['Develop a task', <<<'MD'
                You are building one approved Lodestar task. Follow the agreed plan and
                the project's CONVENTIONS.md.

                1. Build the change.
                2. Write and run automated tests; then actually run the thing to confirm
                   it behaves.
                3. If you changed the structure (tables, components, flows), update
                   DATA-MODEL.md / ARCHITECTURE.md in the SAME change — stale docs mean
                   the change is incomplete.

                When tests are green and the docs mirror reality, advance the task to
                `ready_for_ai_review` and report a work-session summarising what changed.
                MD],

            'ai_review' => ['AI-review a task', <<<'MD'
                You are reviewing a developed Lodestar task — a DIFFERENT agent than the
                one that built it. Review the change against the docs: does it match
                DATA-MODEL.md / ARCHITECTURE.md, and if it changed the structure, did it
                update them?

                Prepare a review with create_review (link the task) and add a section per
                concern with upsert_review_section, choosing the right mode (skip /
                behavioural / direct / direct_doc / mirror_guard). Describe each finding
                as a realistic scenario + impact.

                If the change is sound, advance the task to `human_review` and hand back
                the review URL. If it needs rework, advance it back to `ready_for_dev`
                with the reasons in the review.
                MD],

            'merge' => ['Merge & deploy a task', <<<'MD'
                You are shipping an approved Lodestar task. The human has signed off.

                Merge the change (merge-commit, never squash unless asked), run the full
                test suite once more, and deploy per the project's process. Confirm the
                deploy is healthy.

                Report a work-session with what shipped, then advance the task to `done`.
                If anything blocks the merge, advance back to `approved` and report why.
                MD],
        ];
    }
}
