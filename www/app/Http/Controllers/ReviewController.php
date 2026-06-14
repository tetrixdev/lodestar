<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\ReviewSection;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReviewController extends Controller
{
    /** A project's reviews. */
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $reviews = $project->reviews()->latest()->withCount('sections')->get();

        return view('reviews.index', ['project' => $project, 'reviews' => $reviews]);
    }

    /** The review walkthrough — ordered sections, rebuilt top-to-bottom. */
    public function show(Request $request, Review $review): View
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        $review->load([
            'sections.files:id,path', 'sections.findings', 'project', 'assignee', 'files',
            'tasks' => fn ($q) => $q->orderBy('title'),
        ]);

        return view('reviews.show', ['review' => $review]);
    }

    /** Persist a section's sign-off / comment (called from the walkthrough via fetch). */
    public function updateSection(Request $request, Review $review, ReviewSection $section): JsonResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);
        abort_unless($section->review_id === $review->id, 404);

        // Sign-off is gated on assignment: only the human currently holding the
        // review may sign off or comment on its sections.
        abort_unless($review->assigned_to_user_id === $request->user()->id, 403,
            'Assign this review to yourself before signing off its sections.');

        $data = $request->validate([
            'status' => ['nullable', 'in:open,signed_off'],
            'note' => ['nullable', 'string', 'max:4000'],
            'decision' => ['nullable', 'in:approved,changes_requested'],
        ]);

        // `decision` is intentionally allowed through even when null is sent — the
        // human can clear a decision back to undecided — so it is not array_filter'd
        // away with the other fields.
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
            'signed_off' => $review->sections()->where('status', 'signed_off')->count(),
            'total' => $review->sections()->count(),
            'decisions' => $review->fresh()->decisionSummary(),
        ]);
    }

    /** Triage one finding (approve / dismiss / must_fix); gated on holding the review. */
    public function updateFinding(Request $request, Review $review, ReviewSection $section, ReviewFinding $finding): JsonResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);
        abort_unless($section->review_id === $review->id, 404);
        abort_unless($finding->review_section_id === $section->id, 404);

        abort_unless($review->assigned_to_user_id === $request->user()->id, 403,
            'Assign this review to yourself before triaging its findings.');

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', ReviewFinding::STATUSES)],
        ]);

        $finding->update(['status' => $data['status']]);

        return response()->json(['ok' => true, 'status' => $finding->status]);
    }

    /**
     * Apply the review's outcome to its linked task(s). Allowed only once every
     * section is decided; the verdict (changes_requested if any section requests
     * changes, else approved) drives the task forward (→ approved) or back to the
     * developer (→ ready_for_dev with compiled rework notes). Gated on holding it.
     */
    public function conclude(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);
        abort_unless($review->assigned_to_user_id === $request->user()->id, 403,
            'Assign this review to yourself before concluding it.');

        $review->load(['sections.findings', 'tasks']);
        $summary = $review->decisionSummary();

        if (! $summary['all_decided']) {
            return redirect()->route('reviews.show', $review)
                ->with('status', 'Decide every section before applying the outcome.');
        }

        $verdict = $summary['verdict'];

        DB::transaction(function () use ($review, $verdict) {
            if ($verdict === 'approved') {
                $review->update(['outcome' => 'approved', 'status' => 'done']);
                foreach ($review->tasks as $task) {
                    if ($task->status === Task::STATUS_HUMAN_REVIEW
                        && $task->canTransitionTo(Task::STATUS_APPROVED)) {
                        $task->update(['status' => Task::STATUS_APPROVED]);
                        $task->logEvent('review_approved', $review->assignee?->name,
                            "Review #{$review->id} approved.");
                    }
                }

                return;
            }

            // changes_requested
            $notes = $this->compileReworkNotes($review);
            $review->update(['outcome' => 'changes_requested', 'status' => 'done']);
            foreach ($review->tasks as $task) {
                if ($task->status === Task::STATUS_HUMAN_REVIEW
                    && $task->canTransitionTo(Task::STATUS_READY_FOR_DEV)) {
                    $task->update([
                        'status' => Task::STATUS_READY_FOR_DEV,
                        'rework_notes' => $notes,
                    ]);
                    $task->logEvent('review_changes_requested', $review->assignee?->name,
                        "Review #{$review->id} requested changes; sent back to dev.");
                }
            }
        });

        return redirect()->route('reviews.show', $review)
            ->with('status', $verdict === 'approved'
                ? 'Review approved — linked tasks moved to Approved.'
                : 'Changes requested — linked tasks sent back to the developer with rework notes.');
    }

    /** Markdown rework brief: each changes_requested section's note + every must_fix finding. */
    private function compileReworkNotes(Review $review): string
    {
        $lines = ["# Rework requested (review: {$review->title})", ''];

        foreach ($review->sections as $section) {
            $sectionNote = $section->decision === 'changes_requested' && trim((string) $section->note) !== ''
                ? trim((string) $section->note)
                : null;
            $mustFix = $section->findings->where('status', 'must_fix');

            if ($sectionNote === null && $mustFix->isEmpty()) {
                continue;
            }

            $lines[] = "## {$section->title}";
            if ($sectionNote !== null) {
                $lines[] = $sectionNote;
            }
            foreach ($mustFix as $finding) {
                $sev = strtoupper($finding->severity);
                $lines[] = "- **[{$sev}] {$finding->title}**".
                    (trim((string) $finding->detail) !== '' ? ' — '.trim((string) $finding->detail) : '');
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /** Atomically self-assign this review (succeeds only if currently unassigned). */
    public function assign(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        if (! $review->claimFor($request->user()->id)) {
            $holder = $review->fresh('assignee')->assignee?->name ?? 'someone else';

            return redirect()->route('reviews.show', $review)
                ->with('status', "Already being reviewed by {$holder}.");
        }

        return redirect()->route('reviews.show', $review);
    }

    /** Release this review (only if the requester currently holds it). */
    public function unassign(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        $review->releaseFor($request->user()->id);

        return redirect()->route('reviews.show', $review);
    }
}
