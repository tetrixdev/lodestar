<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
            // The manual-test checklist progress: indices into the section's
            // `checks` the human has ticked. Bounded to the actual item count so a
            // stale/forged index can't be persisted.
            'checked' => ['nullable', 'array'],
            'checked.*' => ['integer', 'min:0', 'max:'.(count((array) $section->checks) - 1)],
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
        // `checked` (manual-test progress) is sent whole each toggle; dedupe + sort
        // for a stable stored shape. An empty array clears all ticks.
        if (array_key_exists('checked', $data)) {
            $checked = array_values(array_unique(array_map('intval', $data['checked'] ?? [])));
            sort($checked);
            $update['checked'] = $checked;
        }
        $section->update($update);

        return response()->json([
            'ok' => true,
            'signed_off' => $review->sections()->where('status', 'signed_off')->count(),
            'total' => $review->sections()->count(),
            'decisions' => $review->fresh()->decisionSummary(),
            'manual_tests' => $review->fresh()->load('sections')->manualTestSummary(),
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
