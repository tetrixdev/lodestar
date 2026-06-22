<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFile;
use App\Models\ReviewFinding;
use App\Models\ReviewSection;
use App\Models\Task;
use App\Services\GitHubComparison;
use App\Support\DiffRenderer;
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
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $reviews = $project->reviews()->latest()->withCount('sections')->get();

        return view('reviews.index', ['project' => $project, 'reviews' => $reviews]);
    }

    /** The review walkthrough — ordered sections, rebuilt top-to-bottom. */
    public function show(Request $request, Review $review): View
    {
        abort_unless($review->project->isAccessibleBy($request->user()), 403);

        $review->load([
            'sections.files:id,path', 'sections.findings', 'project', 'assignee', 'files',
            'tasks' => fn ($q) => $q->orderBy('title'),
        ]);

        return view('reviews.show', ['review' => $review]);
    }

    /**
     * Server-rendered view of one changed file, in one of several progressively-
     * disclosed modes (returned as an HTML fragment the modal injects):
     *  - diff (default for code): the stored unified `patch` — NO GitHub call.
     *  - rich (default for markdown): the rendered markdown of base→head with
     *    inline <ins>/<del> highlights (caxy/php-htmldiff). Falls back to `diff`
     *    when the file is huge or the html-diff throws.
     *  - full: the whole head blob with changed lines highlighted inline.
     *  - preview: markdown files only, rendered via <x-markdown>.
     * Binary / oversized blobs (or a null patch) fall back to a GitHub link.
     */
    public function file(Request $request, Review $review, ReviewFile $file): View
    {
        abort_unless($review->project->isAccessibleBy($request->user()), 403);

        $mode = $request->query('mode', 'diff');
        if (! in_array($mode, ['diff', 'rich', 'full', 'preview'], true)) {
            $mode = 'diff';
        }

        $repo = $review->repository?->full_name;
        $linkSha = $review->head_sha ?: $review->head_ref;
        $githubUrl = $repo && $linkSha
            ? "https://github.com/{$repo}/blob/{$linkSha}/{$file->path}"
            : null;

        $view = fn (array $data) => view('reviews.partials.file-modal', array_merge(
            ['mode' => $mode, 'file' => $file, 'githubUrl' => $githubUrl,
                'rows' => null, 'previewContent' => null, 'richHtml' => null, 'fallback' => null],
            $data,
        ));

        // The raw stored patch, rendered as diff rows. Shared by `diff` mode and
        // as the fallback when a richer mode can't be produced.
        $renderPatch = fn () => $file->patch === null
            ? $view(['mode' => 'diff', 'fallback' => 'No textual diff (binary or too large).'])
            : $view(['mode' => 'diff', 'rows' => app(DiffRenderer::class)->renderPatch($file->patch)]);

        // diff — render the stored patch only. Null patch = binary/oversized.
        if ($mode === 'diff') {
            return $renderPatch();
        }

        // rich / full / preview all need the blob(s); for a removed file the head
        // blob is gone, so read the base side instead.
        $removed = $file->status === 'removed';
        $sha = $removed ? $review->base_sha : $review->head_sha;
        if (! $repo || ! $sha) {
            // For markdown rich mode, degrade to the stored patch rather than a
            // GitHub-only dead end when we can't fetch blobs.
            return $mode === 'rich' && $file->patch !== null
                ? $renderPatch()
                : $view(['fallback' => 'This file can only be viewed on GitHub.']);
        }

        $blobPath = $removed ? ($file->old_path ?? $file->path) : $file->path;

        try {
            $blob = app(GitHubComparison::class)->blob(
                $repo, $sha, $blobPath, $review->repository?->token(),
            );
        } catch (\Throwable $e) {
            return $mode === 'rich' && $file->patch !== null
                ? $renderPatch()
                : $view(['fallback' => 'Could not load this file from GitHub: '.$e->getMessage()]);
        }

        if ($blob['too_large'] || $blob['binary'] || $blob['content'] === null) {
            if ($mode === 'rich' && $file->patch !== null) {
                return $renderPatch();
            }

            return $view(['fallback' => $blob['too_large']
                ? 'File is too large to view inline.'
                : 'Binary file — view on GitHub.']);
        }

        $renderer = app(DiffRenderer::class);

        if ($mode === 'preview') {
            return $view(['previewContent' => $blob['content']]);
        }

        if ($mode === 'rich') {
            // The blob is the head side (or base side for a removed file). Diff
            // the rendered markdown base→head; null = too large / html-diff threw,
            // so fall back to the raw patch.
            $richHtml = $removed
                ? $renderer->renderRichMarkdown($blob['content'], null)
                : $renderer->renderRichMarkdown($this->baseContentFor($review, $file), $blob['content']);

            return $richHtml === null
                ? $renderPatch()
                : $view(['richHtml' => $richHtml]);
        }

        // full file: a removed file is rendered as all-removed (its content is the
        // base side); otherwise diff base→head and render the whole head file.
        // A null result means the file exceeds the LCS line guard → raw patch.
        $rows = $removed
            ? $renderer->renderFullFile($blob['content'], '')
            : $renderer->renderFullFile($this->baseContentFor($review, $file), $blob['content']);

        return $rows === null ? $renderPatch() : $view(['rows' => $rows]);
    }

    /**
     * The base-side content of a (modified/renamed) file, for the full-file diff.
     * Added files have no base; a fetch failure degrades to "all added" rather
     * than failing the view.
     */
    private function baseContentFor(Review $review, ReviewFile $file): ?string
    {
        if ($file->status === 'added' || ! $review->base_sha || ! $review->repository) {
            return null;
        }

        try {
            $blob = app(GitHubComparison::class)->blob(
                $review->repository->full_name,
                $review->base_sha,
                $file->old_path ?? $file->path,
                $review->repository->token(),
            );

            return $blob['content'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Persist a section's sign-off / comment (called from the walkthrough via fetch). */
    public function updateSection(Request $request, Review $review, ReviewSection $section): JsonResponse
    {
        abort_unless($review->project->isAccessibleBy($request->user()), 403);
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
        abort_unless($review->project->isAccessibleBy($request->user()), 403);
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
        abort_unless($review->project->isAccessibleBy($request->user()), 403);
        abort_unless($review->assigned_to_user_id === $request->user()->id, 403,
            'Assign this review to yourself before concluding it.');

        // Conclude is one-shot: once an outcome is recorded, don't re-drive tasks
        // (a stale form re-submit, or a task legally moved back to human_review,
        // could otherwise clobber rework_notes and log duplicate events).
        if ($review->outcome !== null) {
            return redirect()->route('reviews.show', $review)
                ->with('status', 'This review was already concluded.');
        }

        $review->load(['sections.findings', 'tasks']);
        $summary = $review->decisionSummary();

        if (! $summary['all_decided']) {
            return redirect()->route('reviews.show', $review)
                ->with('status', 'Decide every section before applying the outcome.');
        }

        $verdict = $summary['verdict'];

        // A plan review drives its linked task forward to dev (all sections approved
        // AND not flagged incomplete) or back to planning (any changes requested, or
        // the AI flagged the technical-architecture incomplete).
        if ($review->isPlanReview()) {
            return $this->concludePlan($review, $verdict);
        }

        // A deliverable-scoped review drives the DELIVERABLE through its human gates
        // (architecture → functional → approved), not individual tasks.
        if ($review->isDeliverableScoped()) {
            return $this->concludeDeliverable($review, $verdict);
        }

        $moved = 0;

        DB::transaction(function () use ($review, $verdict, &$moved) {
            if ($verdict === 'approved') {
                $review->update(['outcome' => 'approved', 'status' => 'done']);
                foreach ($review->tasks as $task) {
                    if ($task->status === Task::STATUS_HUMAN_REVIEW
                        && $task->canTransitionTo(Task::STATUS_APPROVED)) {
                        // Approval ends the rework cycle — clear the now-stale brief
                        // (its history still lives on the concluded review).
                        $task->update(['status' => Task::STATUS_APPROVED, 'rework_notes' => null]);
                        $task->logEvent('review_approved', $review->assignee?->name,
                            "Review #{$review->id} approved.");
                        $moved++;
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
                    $moved++;
                }
            }
        });

        $tasksNote = $moved === 0
            ? ' (no linked task was at human review, so none moved)'
            : " — {$moved} task".($moved === 1 ? '' : 's').' moved';

        return redirect()->route('reviews.show', $review)
            ->with('status', ($verdict === 'approved'
                ? 'Review approved'
                : 'Changes requested — rework notes written').$tasksNote.'.');
    }

    /**
     * Apply a plan review's verdict to its linked task. All sections approved AND
     * not flagged incomplete → the task's plan is accepted and it joins the dev
     * queue (ready_for_dev). Any changes requested, OR the AI flagged the
     * technical-architecture incomplete → back to ready_for_planning with the
     * compiled brief (section notes + question-finding answers) in rework_notes.
     */
    private function concludePlan(Review $review, ?string $verdict): RedirectResponse
    {
        // The incomplete flag forces return-to-planning regardless of decisions:
        // the AI said the technical side could not be fully planned, so the only
        // outcome is to plan it again with the human's answers.
        $toDev = $verdict === 'approved' && ! $review->plan_incomplete;
        $moved = 0;

        DB::transaction(function () use ($review, $toDev, &$moved) {
            $review->update([
                'outcome' => $toDev ? 'approved' : 'changes_requested',
                'status' => 'done',
            ]);

            $notes = $toDev ? null : $this->compileReworkNotes($review);

            foreach ($review->tasks as $task) {
                if ($task->status !== Task::STATUS_PLAN_REVIEW) {
                    continue;
                }
                $target = $toDev ? Task::STATUS_READY_FOR_DEV : Task::STATUS_READY_FOR_PLANNING;
                if (! $task->canTransitionTo($target)) {
                    continue;
                }
                $task->update([
                    'status' => $target,
                    'rework_notes' => $notes,
                    'position' => (int) $task->project->tasks()->where('status', $target)->max('position') + 1,
                ]);
                $task->logEvent(
                    $toDev ? 'plan_approved' : 'plan_changes_requested',
                    $review->assignee?->name,
                    $toDev ? 'Plan approved — sent to dev.'
                        : ($review->plan_incomplete
                            ? 'Plan flagged incomplete — returned to planning.'
                            : 'Plan changes requested — returned to planning.'),
                );
                $moved++;
            }
        });

        $tasksNote = $moved === 0
            ? ' (no linked task was at plan review, so none moved)'
            : '';

        return redirect()->route('reviews.show', $review)->with('status', ($toDev
            ? 'Plan approved — ready for dev'
            : 'Returned to planning with the compiled brief').$tasksNote.'.');
    }

    /**
     * Apply a deliverable-scoped review's verdict to the deliverable. Approving
     * the architecture review advances it to the final functional sanity; approving
     * that advances it to `approved` (ready to merge). Changes-requested sends the
     * deliverable back to `building`, where the rework becomes corrective task(s).
     */
    private function concludeDeliverable(Review $review, ?string $verdict): RedirectResponse
    {
        $deliverable = $review->deliverable;
        if (! $deliverable) {
            return redirect()->route('reviews.show', $review)
                ->with('status', 'This review has no linked deliverable.');
        }

        $message = '';

        DB::transaction(function () use ($review, $verdict, $deliverable, &$message) {
            if ($verdict === 'approved') {
                $review->update(['outcome' => 'approved', 'status' => 'done']);
                $next = match ($deliverable->status) {
                    Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW => Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW,
                    Deliverable::STATUS_HUMAN_FUNCTIONAL_REVIEW => Deliverable::STATUS_APPROVED,
                    default => null,
                };
                if ($next && $deliverable->canTransitionTo($next)) {
                    $deliverable->update(['status' => $next]);
                    $message = $next === Deliverable::STATUS_APPROVED
                        ? 'Approved — deliverable is ready to merge.'
                        : 'Approved — deliverable advanced to the final functional sanity check.';
                } else {
                    $message = 'Approved.';
                }

                return;
            }

            // changes_requested → spawn corrective fix task(s) under the deliverable.
            // Each fix enters at ready_for_dev (skips planning) and skips the per-task
            // human functional review (needs_functional_review = false) — the human is
            // reviewing at the DELIVERABLE level, so the fix flows ai_review ⇒ approved
            // automatically (still AI-reviewed). The new non-merged task pulls the
            // deliverable back to BUILD via syncStatus().
            $review->update(['outcome' => 'changes_requested', 'status' => 'done']);
            $spawned = $this->spawnCorrectiveTasks($review, $deliverable);
            // syncStatus() on the new task's save already pulls the deliverable back
            // to building; fall back explicitly if nothing was spawned.
            if ($spawned === 0 && $deliverable->canTransitionTo(Deliverable::STATUS_BUILDING)) {
                $deliverable->update(['status' => Deliverable::STATUS_BUILDING]);
            }
            $message = $spawned === 0
                ? 'Changes requested — deliverable sent back to building (no actionable findings to spawn a task from).'
                : 'Changes requested — '.$spawned.' corrective task'.($spawned === 1 ? '' : 's')
                    .' created under the deliverable; it is back in building.';
        });

        return redirect()->route('reviews.show', $review)->with('status', $message);
    }

    /**
     * Markdown rework brief: each changes_requested section's note + the relevant
     * findings. For a plan review the findings ARE the open questions, so every
     * non-dismissed finding is carried back (with the human's answer, kept on the
     * finding's detail/note when triaged); for code/functional reviews only the
     * must_fix findings feed the brief.
     */
    private function compileReworkNotes(Review $review): string
    {
        $isPlan = $review->isPlanReview();
        $heading = $isPlan
            ? "# Plan rework (review: {$review->title})"
            : "# Rework requested (review: {$review->title})";
        $lines = [$heading, ''];

        foreach ($review->sections as $section) {
            $sectionNote = trim((string) $section->note) !== ''
                && ($isPlan || $section->decision === 'changes_requested')
                ? trim((string) $section->note)
                : null;

            // Plan reviews carry every still-open question (anything not dismissed);
            // other reviews carry only the human-flagged must_fix findings.
            $findings = $isPlan
                ? $section->findings->where('status', '!=', 'dismissed')
                : $section->findings->where('status', 'must_fix');

            if ($sectionNote === null && $findings->isEmpty()) {
                continue;
            }

            $lines[] = "## {$section->title}";
            if ($sectionNote !== null) {
                $lines[] = $sectionNote;
            }
            foreach ($findings as $finding) {
                $sev = strtoupper($finding->severity);
                $label = $isPlan ? 'Question' : "[{$sev}]";
                $lines[] = "- **{$label} {$finding->title}**".
                    (trim((string) $finding->detail) !== '' ? ' — '.trim((string) $finding->detail) : '');
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Spawn corrective fix task(s) under a deliverable from a changes-requested
     * deliverable-level review. One summarizing task per deliverable review,
     * compiling every change-requested section note + must_fix finding into its
     * body. The task is corrective (is_corrective = true), skips the per-task
     * human functional review (needs_functional_review = false), enters at
     * ready_for_dev (no planning), and belongs to the deliverable — which
     * therefore drops back to BUILD. Returns the number of tasks created.
     */
    private function spawnCorrectiveTasks(Review $review, Deliverable $deliverable): int
    {
        $body = $this->compileReworkNotes($review);

        // Nothing actionable (no change-requested notes, no must_fix findings) →
        // don't manufacture an empty fix task; the caller falls back to building.
        $heading = "# Rework requested (review: {$review->title})";
        if (trim($body) === '' || trim($body) === $heading) {
            return 0;
        }

        $title = "Corrective: {$review->title}";

        $task = $deliverable->project->tasks()->make([
            'deliverable_id' => $deliverable->id,
            'status' => Task::STATUS_READY_FOR_DEV,
            'is_corrective' => true,
            'needs_functional_review' => false,
            'title' => $title,
            'body' => $body,
            'body_summary' => "Fixes raised by deliverable review “{$review->title}”.",
            'rework_notes' => $body,
            'position' => (int) $deliverable->project->tasks()
                ->where('status', Task::STATUS_READY_FOR_DEV)->max('position') + 1,
        ]);
        $task->save();

        $task->logEvent('review_changes_requested', $review->assignee?->name,
            "Spawned from deliverable review #{$review->id} (changes requested).");

        return 1;
    }

    /** Atomically self-assign this review (succeeds only if currently unassigned). */
    public function assign(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->isAccessibleBy($request->user()), 403);

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
        abort_unless($review->project->isAccessibleBy($request->user()), 403);

        $review->releaseFor($request->user()->id);

        return redirect()->route('reviews.show', $review);
    }
}
