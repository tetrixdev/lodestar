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

#[Description('Create a review (or refresh one) and get back the URL a human opens. TASK reviews: pass task_ids + base_ref/head_ref (+ repo if the project has several). DELIVERABLE reviews: pass scope="deliverable" + deliverable=<id>; the comparison defaults to comparison_ref...deliverable branch. review_type is functional (per-task behaviour/UX) or code/architecture (technical). Every changed file must be covered by a section before a human can see it. Pass review_id to REFRESH an existing review\'s comparison — newly-changed files auto-flag their sections for re-review.')]
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
            'repo' => ['nullable', 'string', 'max:255'],
            'base_ref' => ['nullable', 'string', 'max:255'],
            'head_ref' => ['nullable', 'string', 'max:255'],
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
        $baseRef = $data['base_ref'] ?? null;
        $headRef = $data['head_ref'] ?? null;

        // A deliverable review diffs comparison_ref...deliverable branch by default
        // (the review DIFF-BASE is comparison_ref, which may be a tag; the merge
        // target base_branch is a separate concern handled at merge time).
        if ($scope === Review::SCOPE_DELIVERABLE) {
            if (empty($data['deliverable'])) {
                return Response::error('A deliverable-scoped review needs deliverable=<id>.');
            }
            $deliverable = $this->ownedDeliverable($request, (int) $data['deliverable']);
            if (! $deliverable) {
                return Response::error('No deliverable with that id belongs to you.');
            }
            $baseRef = $baseRef ?: $deliverable->comparison_ref;
            $headRef = $headRef ?: $deliverable->branch;
        }

        $reviewType = $data['review_type']
            ?? ($scope === Review::SCOPE_DELIVERABLE ? Review::TYPE_ARCHITECTURE : Review::TYPE_CODE);

        // Resolve + fetch the comparison (if refs are present).
        $repository = null;
        $files = [];
        $baseSha = $headSha = null;
        if ($baseRef && $headRef) {
            $repository = $this->resolveRepository($project, $data['repo'] ?? null);
            if (! $repository) {
                return Response::error('No matching repository is linked to this project. Link one, or pass repo="owner/name".');
            }
            try {
                [$baseSha, $headSha, $files] = $this->fetchComparison($repository, $baseRef, $headRef);
            } catch (Throwable $e) {
                return Response::error('Could not fetch the comparison: '.$e->getMessage());
            }
        }

        $review = DB::transaction(function () use ($project, $data, $scope, $deliverable, $reviewType, $repository, $files, $baseRef, $headRef, $baseSha, $headSha) {
            $review = $project->reviews()->create([
                'title' => $data['title'],
                'scope' => $scope,
                'deliverable_id' => $deliverable?->id,
                'review_type' => $reviewType,
                'base_branch' => $scope === Review::SCOPE_DELIVERABLE ? $deliverable?->comparison_ref : null,
                'repository_id' => $repository?->id,
                'base_ref' => $baseRef,
                'base_sha' => $baseSha,
                'head_ref' => $headRef,
                'head_sha' => $headSha,
                'intro' => $data['intro'] ?? null,
                'status' => 'draft',
            ]);

            if ($files !== []) {
                $review->files()->createMany($files);
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
            'repository' => $repository?->full_name,
            'coverage' => $review->coverage(),
            'next' => $files !== []
                ? 'Group coverage.uncovered into sections with upsert_review_section. Functional reviews: set each section\'s kind (input→output) and manual_steps. The review cannot reach a human until coverage is complete.'
                : 'Add sections with upsert_review_section.',
        ]);
    }

    /**
     * Re-fetch a review's comparison and reconcile its files: add new files,
     * update changed ones (keeping their section links), drop removed ones, and
     * auto-flag every section covering a changed/added file for re-review. Re-opens
     * the review to draft so the new files can be allocated to sections.
     */
    private function refresh(Request $request, int $reviewId): Response
    {
        $review = $this->ownedReview($request, $reviewId);
        if (! $review) {
            return Response::error('No review with that id belongs to you.');
        }
        if (! $review->repository_id || ! $review->base_ref || ! $review->head_ref) {
            return Response::error('This review has no comparison to refresh (it was created without base/head refs).');
        }

        $repository = $review->repository;
        try {
            [$baseSha, $headSha, $files] = $this->fetchComparison($repository, $review->base_ref, $review->head_ref);
        } catch (Throwable $e) {
            return Response::error('Could not refresh the comparison: '.$e->getMessage());
        }

        $changed = DB::transaction(function () use ($review, $files, $baseSha, $headSha) {
            $review->update(['base_sha' => $baseSha, 'head_sha' => $headSha, 'status' => 'draft']);

            $existing = $review->files()->get()->keyBy('path');
            $newByPath = collect($files)->keyBy('path');
            $changedPaths = [];

            foreach ($newByPath as $path => $f) {
                $old = $existing->get($path);
                if (! $old) {
                    $review->files()->create($f);          // new file
                    $changedPaths[] = $path;
                } elseif (($old->patch ?? null) !== ($f['patch'] ?? null)) {
                    $old->update($f);                       // changed file (section links preserved)
                    $changedPaths[] = $path;
                }
            }
            // Files no longer in the comparison.
            foreach ($existing as $path => $old) {
                if (! $newByPath->has($path)) {
                    $old->delete();
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
            'review_id' => $schema->integer()->description('Refresh this existing review\'s comparison (re-fetch files; sections covering changed files auto-flag for re-review).'),
            'scope' => $schema->string()->enum([Review::SCOPE_TASK, Review::SCOPE_DELIVERABLE])->description('task (default) or deliverable (whole-deliverable diff).'),
            'deliverable' => $schema->integer()->description('For a deliverable review: the deliverable id. Comparison defaults to its comparison_ref...branch.'),
            'review_type' => $schema->string()->enum([Review::TYPE_FUNCTIONAL, Review::TYPE_CODE, Review::TYPE_ARCHITECTURE])->description('functional (per-task behaviour/UX/UI), code (task technical), or architecture (deliverable technical). Defaults: task→code, deliverable→architecture.'),
            'title' => $schema->string()->description('Review title.'),
            'repo' => $schema->string()->description('Which linked repo ("owner/name") the comparison is in. Optional if the project has one repo.'),
            'base_ref' => $schema->string()->description('Base ref, e.g. "main". For a deliverable, defaults to its comparison_ref (the review diff-base).'),
            'head_ref' => $schema->string()->description('Head ref under review. For a deliverable, defaults to its branch.'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Task ids (in this project) this review covers.'),
        ];
    }
}
