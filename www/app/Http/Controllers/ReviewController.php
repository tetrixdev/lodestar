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

        $reviews = $project->reviews()->latest()
            ->withCount('sections')
            ->with('comparisons.repository')
            ->get();

        return view('reviews.index', ['project' => $project, 'reviews' => $reviews]);
    }

    /** The review walkthrough — ordered sections, rebuilt top-to-bottom. */
    public function show(Request $request, Review $review): View
    {
        abort_unless($review->project->isAccessibleBy($request->user()), 403);

        $review->load([
            'sections.files:id,path', 'sections.findings', 'project', 'assignee',
            'comparisons.repository', 'comparisons.files',
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

        // A file is reviewed within its own comparison (repo + base/head SHAs).
        abort_unless($file->comparison?->review_id === $review->id, 404);
        $comparison = $file->comparison;

        $mode = $request->query('mode', 'diff');
        if (! in_array($mode, ['diff', 'rich', 'full', 'preview'], true)) {
            $mode = 'diff';
        }

        $repo = $comparison->repository?->full_name;
        $linkSha = $comparison->head_sha ?: $comparison->head_ref;
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
        $sha = $removed ? $comparison->base_sha : $comparison->head_sha;
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
                $repo, $sha, $blobPath, $comparison->repository?->token(),
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
                : $renderer->renderRichMarkdown($this->baseContentFor($file), $blob['content']);

            return $richHtml === null
                ? $renderPatch()
                : $view(['richHtml' => $richHtml]);
        }

        // full file: a removed file is rendered as all-removed (its content is the
        // base side); otherwise diff base→head and render the whole head file.
        // A null result means the file exceeds the LCS line guard → raw patch.
        $rows = $removed
            ? $renderer->renderFullFile($blob['content'], '')
            : $renderer->renderFullFile($this->baseContentFor($file), $blob['content']);

        return $rows === null ? $renderPatch() : $view(['rows' => $rows]);
    }

    /**
     * The base-side content of a (modified/renamed) file, for the full-file diff,
     * read from its own comparison. Added files have no base; a fetch failure
     * degrades to "all added" rather than failing the view.
     */
    private function baseContentFor(ReviewFile $file): ?string
    {
        $comparison = $file->comparison;
        if ($file->status === 'added' || ! $comparison?->base_sha || ! $comparison->repository) {
            return null;
        }

        try {
            $blob = app(GitHubComparison::class)->blob(
                $comparison->repository->full_name,
                $comparison->base_sha,
                $file->old_path ?? $file->path,
                $comparison->repository->token(),
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

            // changes_requested → back to building; rework lands as corrective task(s).
            $review->update(['outcome' => 'changes_requested', 'status' => 'done']);
            if ($deliverable->canTransitionTo(Deliverable::STATUS_BUILDING)) {
                $deliverable->update(['status' => Deliverable::STATUS_BUILDING]);
            }
            $message = 'Changes requested — deliverable sent back to building; add corrective task(s) for the fixes.';
        });

        return redirect()->route('reviews.show', $review)->with('status', $message);
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
