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

#[Description('Create a review on one of your projects and get back the URL a human opens to walk through it. Provide base_ref + head_ref (and repo "owner/name" if the project has several) to pull the authoritative changed-file list from GitHub via that repo\'s connection — every changed file must then be covered by a section before the review can reach a human. Add sections with upsert_review_section.')]
#[Name('create_review')]
class CreateReviewTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'repo' => ['nullable', 'string', 'max:255'],
            'base_ref' => ['nullable', 'string', 'max:255'],
            'head_ref' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['integer'],
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project "'.$data['project'].'" belongs to you.');
        }

        // A comparison review resolves to one of the project's linked repositories
        // and reads GitHub through that repo's connection token.
        $files = [];
        $repository = null;
        if (! empty($data['base_ref']) && ! empty($data['head_ref'])) {
            $repository = $this->resolveRepository($project, $data['repo'] ?? null);
            if (! $repository) {
                return Response::error(
                    'No matching repository is linked to this project. Link one in the project\'s Repositories, '
                    .'or pass repo="owner/name" of a linked repo.'
                );
            }
            try {
                $files = app(GitHubComparison::class)->files(
                    $repository->full_name, $data['base_ref'], $data['head_ref'], $repository->token()
                );
            } catch (Throwable $e) {
                return Response::error('Could not fetch the comparison: '.$e->getMessage());
            }
        }

        $review = DB::transaction(function () use ($project, $data, $repository, $files) {
            $review = $project->reviews()->create([
                'title' => $data['title'],
                'repository_id' => $repository?->id,
                'base_ref' => $data['base_ref'] ?? null,
                'head_ref' => $data['head_ref'] ?? null,
                'intro' => $data['intro'] ?? null,
                'status' => 'draft',
            ]);

            if ($files !== []) {
                $review->files()->createMany($files);
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
            'repository' => $repository?->full_name,
            'linked_tasks' => $review->tasks()->count(),
            // The full worklist up front (same shape as upsert_review_section /
            // get_review): coverage.uncovered is every changed file you must
            // allocate to a section. It shrinks as you add sections; the review
            // can't reach a human until coverage.complete is true.
            'coverage' => $review->coverage(),
            'next' => $files !== []
                ? 'Group coverage.uncovered into sections with upsert_review_section (pass each section its "files" + a review mode). Each call returns the remaining uncovered list. The review cannot reach a human until coverage is complete.'
                : 'Add sections with upsert_review_section.',
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
            'repo' => $schema->string()->description('Which linked repo ("owner/name") the comparison is in. Optional if the project has exactly one repo.'),
            'base_ref' => $schema->string()->description('Base ref of the comparison, e.g. "main". Required (with head_ref) to pull the file list.'),
            'head_ref' => $schema->string()->description('Head ref under review, e.g. "feat/x".'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Optional task ids (in this project) this review covers.'),
        ];
    }
}
