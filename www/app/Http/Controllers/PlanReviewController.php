<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlanReviewSection;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The plan-review walkthrough — the plan-side mirror of ReviewController. A task
 * at `plan_review` carries an ordered set of plan-review sections (the planning
 * agent's structure map, sliced for review). A human atomically self-assigns the
 * plan review, signs off / decides each section, then concludes: approve →
 * `ready_for_dev`; request changes → `ready_for_planning` with compiled
 * `plan_rework_notes` the planning agent reads on its next pass.
 *
 * The plan-review walkthrough lives on the task detail page, so there is no
 * `show()` here — these are the write paths the page's walkthrough calls.
 */
class PlanReviewController extends Controller
{
    /** Atomically self-assign this card's plan review (succeeds only if unassigned). */
    public function assign(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        if (! $task->claimPlanReviewFor($request->user()->id)) {
            $holder = $task->fresh('planReviewer')->planReviewer?->name ?? 'someone else';

            return redirect()->route('tasks.show', $task)
                ->with('status', "This plan is already being reviewed by {$holder}.");
        }

        return redirect()->route('tasks.show', $task);
    }

    /** Release this card's plan review (only if the requester currently holds it). */
    public function unassign(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);

        $task->releasePlanReviewFor($request->user()->id);

        return redirect()->route('tasks.show', $task);
    }

    /** Persist a section's sign-off / decision / note (called from the walkthrough via fetch). */
    public function updateSection(Request $request, Task $task, PlanReviewSection $section): JsonResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);
        abort_unless($section->task_id === $task->id, 404);

        // Gated on the hold: only the human currently holding the plan review may
        // sign off, decide, or comment on its sections.
        abort_unless($task->plan_reviewer_id === $request->user()->id, 403,
            'Assign this plan review to yourself before signing off its sections.');

        $data = $request->validate([
            'status' => ['nullable', 'in:open,signed_off'],
            'note' => ['nullable', 'string', 'max:4000'],
            'decision' => ['nullable', 'in:approved,changes_requested'],
        ]);

        // `decision` is allowed through even when null is sent (the human can clear
        // a decision back to undecided), so it is not array_filter'd away.
        $update = array_filter(
            ['status' => $data['status'] ?? null, 'note' => $data['note'] ?? null],
            fn ($v) => $v !== null,
        );
        if (array_key_exists('decision', $data)) {
            $update['decision'] = $data['decision'];
        }
        $section->update($update);

        return response()->json([
            'ok' => true,
            'signed_off' => $task->planReviewSections()->where('status', 'signed_off')->count(),
            'total' => $task->planReviewSections()->count(),
            'decisions' => $task->fresh()->planDecisionSummary(),
        ]);
    }

    /**
     * Apply the plan review's outcome to the task. Allowed only once every section
     * is decided; approve → `ready_for_dev`, request-changes → `ready_for_planning`
     * with compiled rework notes. Gated on holding the plan review.
     */
    public function conclude(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->isAccessibleBy($request->user()), 403);
        abort_unless($task->plan_reviewer_id === $request->user()->id, 403,
            'Assign this plan review to yourself before concluding it.');

        // Only conclude from the gate itself — a stale form re-submit on a card
        // that already moved on must not re-drive the lifecycle.
        if ($task->status !== Task::STATUS_PLAN_REVIEW) {
            return redirect()->route('tasks.show', $task)
                ->with('status', 'This plan review has already been concluded.');
        }

        $task->load('planReviewSections');
        $summary = $task->planDecisionSummary();

        if (! $summary['all_decided']) {
            return redirect()->route('tasks.show', $task)
                ->with('status', 'Decide every section before applying the outcome.');
        }

        $verdict = $summary['verdict'];
        $reviewer = $request->user()->name;

        DB::transaction(function () use ($task, $verdict, $reviewer): void {
            if ($verdict === 'approved') {
                // Approval ends the plan-rework cycle — clear the now-stale brief
                // and release the hold (its history lives on the sections).
                $task->update([
                    'status' => Task::STATUS_READY_FOR_DEV,
                    'plan_rework_notes' => null,
                    'plan_reviewer_id' => null,
                ]);
                $task->logEvent('plan_approved', $reviewer, 'Plan approved; sent to dev.');

                return;
            }

            // changes_requested → back to planning with the compiled brief.
            $task->update([
                'status' => Task::STATUS_READY_FOR_PLANNING,
                'plan_rework_notes' => $this->compilePlanReworkNotes($task),
                'plan_reviewer_id' => null,
            ]);
            $task->logEvent('plan_changes_requested', $reviewer,
                'Plan changes requested; sent back to planning.');
        });

        return redirect()->route('tasks.show', $task)
            ->with('status', $verdict === 'approved'
                ? 'Plan approved — sent to dev.'
                : 'Changes requested — plan rework notes written, sent back to planning.');
    }

    /** Markdown rework brief: each changes_requested section's note. */
    private function compilePlanReworkNotes(Task $task): string
    {
        $lines = ['# Plan rework requested', ''];

        foreach ($task->planReviewSections as $section) {
            if ($section->decision !== 'changes_requested' || trim((string) $section->note) === '') {
                continue;
            }

            $lines[] = "## {$section->title}";
            $lines[] = trim((string) $section->note);
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
