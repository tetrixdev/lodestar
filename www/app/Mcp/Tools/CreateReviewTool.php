<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Repository;
use App\Services\GitHubComparison;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

#[Description('Create a review on one of your projects and get back the URL a human opens to walk through it. A review needs at least one comparison: pass `comparisons` (a list of {repo, base_ref, head_ref}) to review a change that spans several repos, or the single `repo`/`base_ref`/`head_ref` for a one-repo change. Lodestar pulls each comparison\'s authoritative changed-file list from GitHub via that repo\'s connection; every changed file must then be covered by a section before the review can reach a human. Add sections with upsert_review_section.')]
#[Name('create_review')]
class CreateReviewTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
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

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project "'.$data['project'].'" belongs to you.');
        }

        // Normalise both shapes into one list of {repo?, base_ref, head_ref}.
        $requested = $data['comparisons'] ?? [];
        if ($requested === [] && ! empty($data['base_ref']) && ! empty($data['head_ref'])) {
            $requested = [['repo' => $data['repo'] ?? null, 'base_ref' => $data['base_ref'], 'head_ref' => $data['head_ref']]];
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

        if ($prepared === []) {
            return Response::error(
                'A review needs at least one comparison. Pass `comparisons` (a list of {repo, base_ref, head_ref}) '
                .'or the single repo/base_ref/head_ref.'
            );
        }

        $review = DB::transaction(function () use ($project, $data, $prepared) {
            $review = $project->reviews()->create([
                'title' => $data['title'],
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
                $ids = $project->tasks()->whereIn('id', $data['task_ids'])->pluck('id');
                $review->tasks()->sync($ids);
            }

            return $review;
        });

        return Response::json([
            'id' => $review->id,
            'url' => route('reviews.show', $review),
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
                ? 'Group coverage.uncovered into sections with upsert_review_section (pass each section its "files" + a review mode). Each call returns the remaining uncovered list. The review cannot reach a human until coverage is complete.'
                : 'No files changed in any comparison. Add sections with upsert_review_section.',
        ]);
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
            'project' => $schema->string()->description('Project id or slug.')->required(),
            'title' => $schema->string()->description('Review title.')->required(),
            'repo' => $schema->string()->description('Single-comparison shape: which linked repo ("owner/name"). Optional if the project has exactly one repo.'),
            'base_ref' => $schema->string()->description('Single-comparison base ref, e.g. "main".'),
            'head_ref' => $schema->string()->description('Single-comparison head ref, e.g. "feat/x".'),
            'comparisons' => $schema->array()->description('Multi-repo shape: a list of {repo, base_ref, head_ref} — one per repository the change spans. Use this instead of the single repo/base_ref/head_ref for a multi-repo review.'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Optional task ids (in this project) this review covers.'),
        ];
    }
}
