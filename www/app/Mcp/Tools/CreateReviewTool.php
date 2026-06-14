<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\GitHubComparison;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

#[Description('Create a review on one of your projects and get back the URL a human opens to walk through it. Provide base_ref + head_ref (and repo, "owner/name") to pull the authoritative changed-file list from GitHub — every changed file must then be covered by a section before the review can reach a human. Add sections with upsert_review_section.')]
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

        // If a comparison was given, fetch the authoritative changed files first —
        // a review that can't list its files shouldn't be created half-formed.
        $files = [];
        $repo = $data['repo'] ?? $this->repoFromProject($project);
        if (! empty($data['base_ref']) && ! empty($data['head_ref'])) {
            if (! $repo) {
                return Response::error('Provide repo ("owner/name") — none could be derived from the project.');
            }
            try {
                $files = app(GitHubComparison::class)->files($repo, $data['base_ref'], $data['head_ref']);
            } catch (Throwable $e) {
                return Response::error('Could not fetch the comparison: '.$e->getMessage());
            }
        }

        $review = DB::transaction(function () use ($project, $data, $repo, $files) {
            $review = $project->reviews()->create([
                'title' => $data['title'],
                'repo' => $repo,
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
            'linked_tasks' => $review->tasks()->count(),
            'files' => count($files),
            'next' => $files !== []
                ? 'Add sections with upsert_review_section and allocate every changed file (pass its "files"). The review cannot reach a human until coverage is complete.'
                : 'Add sections with upsert_review_section.',
        ]);
    }

    /** Best-effort owner/name from the project's first repo URL (github.com/owner/name). */
    private function repoFromProject($project): ?string
    {
        foreach ((array) ($project->repos ?? []) as $repo) {
            $url = is_array($repo) ? ($repo['url'] ?? '') : '';
            if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#', $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug.')->required(),
            'title' => $schema->string()->description('Review title.')->required(),
            'repo' => $schema->string()->description('GitHub repo "owner/name". Defaults from the project\'s repo URL if set.'),
            'base_ref' => $schema->string()->description('Base ref of the comparison, e.g. "main". Required (with head_ref) to pull the file list.'),
            'head_ref' => $schema->string()->description('Head ref under review, e.g. "feat/x".'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Optional task ids (in this project) this review covers.'),
        ];
    }
}
