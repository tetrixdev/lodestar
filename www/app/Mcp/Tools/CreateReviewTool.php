<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Review;
use App\Services\GitHubComparison;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

#[Description('Create a review (or refresh one) and get back the URL a human opens to walk through it. A review needs at least one comparison: pass `comparisons` (a list of {repo, base_ref, head_ref}) to review a change that spans several repos, or the single `repo`/`base_ref`/`head_ref` for a one-repo change. TASK reviews: pass task_ids. DELIVERABLE reviews: pass scope="deliverable" + deliverable=<id>; the comparison defaults to base_branch...deliverable branch. review_type is functional (per-task behaviour/UX) or code/architecture (technical). Lodestar pulls each comparison\'s authoritative changed-file list from GitHub via that repo\'s connection; every changed file must then be covered by a section before the review can reach a human. Pass review_id to REFRESH an existing review\'s comparisons — newly-changed files auto-flag their sections for re-review. Add sections with upsert_review_section.')]
#[Name('create_review')]
class CreateReviewTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required_without:review_id', 'string'],
            'review_id' => ['nullable', 'integer'],
            'scope' => ['nullable', 'string', 'in:'.Review::SCOPE_TASK.','.Review::SCOPE_DELIVERABLE],
            'deliverable' => ['nullable', 'integer'],
            'review_type' => ['nullable', 'string', 'in:'.Review::TYPE_FUNCTIONAL.','.Review::TYPE_CODE.','.Review::TYPE_ARCHITECTURE],
            'title' => ['required_without:review_id', 'string', 'max:255'],
            // Legacy single-comparison shape (still accepted).
            'repo' => ['nullable', 'string', 'max:255'],
            'base_ref' => ['nullable', 'string', 'max:255'],
            'head_ref' => ['nullable', 'string', 'max:255'],
            // Multi-repo shape: one entry per comparison.
            'comparisons' => ['nullable', 'array'],
            'comparisons.*.repo' => ['nullable', 'string', 'max:255'],
            'comparisons.*.base_ref' => ['required_with:comparisons', 'string', 'max:255'],
            'comparisons.*.head_ref' => ['required_with:comparisons', 'string', 'max:255'],
            'intro' => ['nullable', 'string'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['integer'],
        ]);

        // ── REFRESH an existing review's comparison (auto-flags changed sections) ──
        if (! empty($data['review_id'])) {
            return $this->refresh($request, (int) $data['review_id']);
        }

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project "'.$data['project'].'" belongs to you.');
        }

        $scope = $data['scope'] ?? Review::SCOPE_TASK;
        $deliverable = null;
        $reviewType = $data['review_type']
            ?? ($scope === Review::SCOPE_DELIVERABLE ? Review::TYPE_ARCHITECTURE : Review::TYPE_CODE);

        // Normalise both shapes into one list of {repo?, base_ref, head_ref}.
        $requested = $data['comparisons'] ?? [];
        if ($requested === [] && ! empty($data['base_ref']) && ! empty($data['head_ref'])) {
            $requested = [['repo' => $data['repo'] ?? null, 'base_ref' => $data['base_ref'], 'head_ref' => $data['head_ref']]];
        }

        // A deliverable review diffs base_branch...deliverable branch by default —
        // its single comparison is derived from the deliverable when none is given.
        if ($scope === Review::SCOPE_DELIVERABLE) {
            if (empty($data['deliverable'])) {
                return Response::error('A deliverable-scoped review needs deliverable=<id>.');
            }
            $deliverable = $this->ownedDeliverable($request, (int) $data['deliverable']);
            if (! $deliverable) {
                return Response::error('No deliverable with that id belongs to you.');
            }
            // Only derive a comparison once the deliverable has a branch to diff;
            // a freshly-typed deliverable review can be created diff-less and gain
            // its comparison later (the ≥1-diff gate is enforced at hand-off, not here).
            if ($requested === [] && $deliverable->base_branch && $deliverable->branch) {
                $requested = [[
                    'repo' => $data['repo'] ?? null,
                    'base_ref' => $deliverable->base_branch,
                    'head_ref' => $deliverable->branch,
                ]];
            }
        }

        // Resolve + fetch each comparison up front so a bad ref/repo fails the
        // whole create rather than leaving a half-built review.
        $prepared = [];
        $github = app(GitHubComparison::class);
        foreach ($requested as $i => $c) {
            $repository = $this->resolveRepository($project, $c['repo'] ?? null);
            if (! $repository) {
                return Response::error(
                    'Comparison #'.($i + 1).': no matching repository is linked to this project. '
                    .'Link one in the project\'s Repositories, or pass repo="owner/name" of a linked repo.'
                );
            }
            try {
                $prepared[] = [
                    'repository' => $repository,
                    'base_ref' => $c['base_ref'],
                    'head_ref' => $c['head_ref'],
                    'base_sha' => $github->resolveSha($repository->full_name, $c['base_ref'], $repository->token()),
                    'head_sha' => $github->resolveSha($repository->full_name, $c['head_ref'], $repository->token()),
                    'files' => $github->files($repository->full_name, $c['base_ref'], $c['head_ref'], $repository->token()),
                ];
            } catch (Throwable $e) {
                return Response::error('Could not fetch comparison #'.($i + 1).': '.$e->getMessage());
            }
        }

        // The ≥1-diff rule is enforced at the hand-off gate (AdvanceTaskTool /
        // AdvanceDeliverableTool refuse `→ human review` for a review with no
        // comparison), so a review can be created diff-less — a typed deliverable
        // or functional review whose comparison is added later. We still nudge the
        // common single-/multi-repo case toward supplying refs in `next`.
        $review = DB::transaction(function () use ($project, $data, $scope, $deliverable, $reviewType, $prepared) {
            $review = $project->reviews()->create([
                'title' => $data['title'],
                'scope' => $scope,
                'deliverable_id' => $deliverable?->id,
                'review_type' => $reviewType,
                'base_branch' => $scope === Review::SCOPE_DELIVERABLE ? $deliverable?->base_branch : null,
                'intro' => $data['intro'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($prepared as $position => $c) {
                $comparison = $review->comparisons()->create([
                    'repository_id' => $c['repository']->id,
                    'base_ref' => $c['base_ref'],
                    'base_sha' => $c['base_sha'],
                    'head_ref' => $c['head_ref'],
                    'head_sha' => $c['head_sha'],
                    'position' => $position,
                ]);
                if ($c['files'] !== []) {
                    $comparison->files()->createMany($c['files']);
                }
            }
            if (! empty($data['task_ids'])) {
                $review->tasks()->sync($project->tasks()->whereIn('id', $data['task_ids'])->pluck('id'));
            }

            return $review;
        });

        return Response::json([
            'id' => $review->id,
            'url' => route('reviews.show', $review),
            'scope' => $review->scope,
            'review_type' => $review->review_type,
            'comparisons' => collect($prepared)->map(fn ($c) => [
                'repository' => $c['repository']->full_name,
                'base_ref' => $c['base_ref'],
                'head_ref' => $c['head_ref'],
                'files' => count($c['files']),
            ])->all(),
            'linked_tasks' => $review->tasks()->count(),
            // The full worklist up front (same shape as upsert_review_section /
            // get_review): coverage.uncovered is every changed file you must
            // allocate to a section. It shrinks as you add sections; the review
            // can't reach a human until coverage.complete is true.
            'coverage' => $review->coverage(),
            'next' => $review->files()->exists()
                ? 'Group coverage.uncovered into sections with upsert_review_section (pass each section its "files" + a review mode). Functional reviews: set each section\'s kind (input→output) and manual_steps. Each call returns the remaining uncovered list. The review cannot reach a human until coverage is complete.'
                : 'No files changed in any comparison. Add sections with upsert_review_section.',
        ]);
    }

    /**
     * Re-fetch every comparison of a review and reconcile its files: add new files,
     * update changed ones (keeping their section links), drop removed ones, and
     * auto-flag every section covering a changed/added file for re-review. Re-opens
     * the review to draft so the new files can be allocated to sections. A
     * comparison missing repo/base/head is skipped rather than failing the run.
     */
    private function refresh(Request $request, int $reviewId): Response
    {
        $review = $this->ownedReview($request, $reviewId);
        if (! $review) {
            return Response::error('No review with that id belongs to you.');
        }
        $comparisons = $review->comparisons()->with('repository')->get();
        if ($comparisons->isEmpty()) {
            return Response::error('This review has no comparison to refresh (it was created without any comparison).');
        }

        // Resolve + fetch each refreshable comparison up front so a bad ref/repo
        // fails the whole refresh rather than leaving it half-applied.
        $fetched = [];
        foreach ($comparisons as $comparison) {
            if (! $comparison->repository_id || ! $comparison->base_ref || ! $comparison->head_ref) {
                continue; // nothing to compare for this row
            }
            try {
                [$baseSha, $headSha, $files] = $this->fetchComparison($comparison->repository, $comparison->base_ref, $comparison->head_ref);
            } catch (Throwable $e) {
                return Response::error('Could not refresh comparison '.$comparison->label().': '.$e->getMessage());
            }
            $fetched[$comparison->id] = ['comparison' => $comparison, 'base_sha' => $baseSha, 'head_sha' => $headSha, 'files' => $files];
        }

        if ($fetched === []) {
            return Response::error('This review has no comparison to refresh (no comparison has repo + base/head refs).');
        }

        $changed = DB::transaction(function () use ($review, $fetched) {
            $review->update(['status' => 'draft']);
            $changedPaths = [];

            foreach ($fetched as $f) {
                $comparison = $f['comparison'];
                $comparison->update(['base_sha' => $f['base_sha'], 'head_sha' => $f['head_sha']]);

                $existing = $comparison->files()->get()->keyBy('path');
                $newByPath = collect($f['files'])->keyBy('path');

                foreach ($newByPath as $path => $file) {
                    $old = $existing->get($path);
                    if (! $old) {
                        $comparison->files()->create($file);   // new file
                        $changedPaths[] = $path;
                    } elseif (($old->patch ?? null) !== ($file['patch'] ?? null)) {
                        $old->update($file);                    // changed file (section links preserved)
                        $changedPaths[] = $path;
                    }
                }
                // Files no longer in this comparison.
                foreach ($existing as $path => $old) {
                    if (! $newByPath->has($path)) {
                        $old->delete();
                    }
                }
            }

            $review->flagStaleSections($changedPaths, 'Comparison refreshed; these files changed: '.implode(', ', $changedPaths).'.');

            return $changedPaths;
        });

        return Response::json([
            'id' => $review->id,
            'url' => route('reviews.show', $review),
            'refreshed' => true,
            'changed_files' => $changed,
            'coverage' => $review->coverage(),
            'next' => 'Sections covering changed files were flagged for re-review. Allocate any newly-uncovered files to sections; the review can\'t reach a human until coverage is complete again.',
        ]);
    }

    /** @return array{0:?string,1:?string,2:array} [baseSha, headSha, files] */
    private function fetchComparison(Repository $repository, string $baseRef, string $headRef): array
    {
        $comparison = app(GitHubComparison::class);

        return [
            $comparison->resolveSha($repository->full_name, $baseRef, $repository->token()),
            $comparison->resolveSha($repository->full_name, $headRef, $repository->token()),
            $comparison->files($repository->full_name, $baseRef, $headRef, $repository->token()),
        ];
    }

    private function ownedDeliverable(Request $request, int $id): ?Deliverable
    {
        return Deliverable::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey($id)
            ->first();
    }

    /** A repository linked to the project: named by full_name, or the sole one if unambiguous. */
    private function resolveRepository(Project $project, ?string $fullName): ?Repository
    {
        $repos = $project->repositories();

        if ($fullName) {
            return $repos->where('full_name', $fullName)->first();
        }

        return $repos->count() === 1 ? $repos->first() : null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (required unless refreshing with review_id).'),
            'review_id' => $schema->integer()->description('Refresh this existing review\'s comparisons (re-fetch files; sections covering changed files auto-flag for re-review).'),
            'scope' => $schema->string()->enum([Review::SCOPE_TASK, Review::SCOPE_DELIVERABLE])->description('task (default) or deliverable (whole-deliverable diff).'),
            'deliverable' => $schema->integer()->description('For a deliverable review: the deliverable id. Comparison defaults to its base_branch...branch.'),
            'review_type' => $schema->string()->enum([Review::TYPE_FUNCTIONAL, Review::TYPE_CODE, Review::TYPE_ARCHITECTURE])->description('functional (per-task behaviour/UX/UI), code (task technical), or architecture (deliverable technical). Defaults: task→code, deliverable→architecture.'),
            'title' => $schema->string()->description('Review title.'),
            'repo' => $schema->string()->description('Single-comparison shape: which linked repo ("owner/name"). Optional if the project has exactly one repo.'),
            'base_ref' => $schema->string()->description('Single-comparison base ref, e.g. "main". For a deliverable, defaults to its base_branch.'),
            'head_ref' => $schema->string()->description('Single-comparison head ref, e.g. "feat/x". For a deliverable, defaults to its branch.'),
            'comparisons' => $schema->array()->description('Multi-repo shape: a list of {repo, base_ref, head_ref} — one per repository the change spans. Use this instead of the single repo/base_ref/head_ref for a multi-repo review.'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Task ids (in this project) this review covers.'),
        ];
    }
}
