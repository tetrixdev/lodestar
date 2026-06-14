<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create a review on one of your projects and get back the URL a human opens to walk through it. Optionally link the tasks it covers. Add sections with upsert_review_section.')]
#[Name('create_review')]
class CreateReviewTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
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

        $review = $project->reviews()->create([
            'title' => $data['title'],
            'base_ref' => $data['base_ref'] ?? null,
            'head_ref' => $data['head_ref'] ?? null,
            'intro' => $data['intro'] ?? null,
            'status' => 'draft',
        ]);

        // Link only tasks that live in this same (owned) project.
        if (! empty($data['task_ids'])) {
            $ids = $project->tasks()->whereIn('id', $data['task_ids'])->pluck('id');
            $review->tasks()->sync($ids);
        }

        return Response::json([
            'id' => $review->id,
            'url' => route('reviews.show', $review),
            'linked_tasks' => $review->tasks()->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug.')->required(),
            'title' => $schema->string()->description('Review title.')->required(),
            'base_ref' => $schema->string()->description('Base ref being compared against, e.g. "main".'),
            'head_ref' => $schema->string()->description('Head ref under review, e.g. "feat/x".'),
            'intro' => $schema->string()->description('Optional preamble shown at the top of the walkthrough.'),
            'task_ids' => $schema->array()->description('Optional task ids (in this project) this review covers.'),
        ];
    }
}
