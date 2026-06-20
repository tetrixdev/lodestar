<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Read a review you own in full: its metadata, every walkthrough section in order, and the tasks it covers.')]
#[Name('get_review')]
class GetReviewTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'review_id' => ['required', 'integer'],
        ]);

        $review = $this->ownedReview($request, (int) $data['review_id']);
        if (! $review) {
            return Response::error('No review with that id belongs to you.');
        }

        $review->load('sections.files:id,path', 'tasks', 'assignee', 'comparisons.repository', 'comparisons.files');

        return Response::json([
            'id' => $review->id,
            'title' => $review->title,
            'status' => $review->status,
            'comparisons' => $review->comparisons->map(fn ($c) => [
                'repository' => $c->repository?->full_name,
                'base_ref' => $c->base_ref,
                'head_ref' => $c->head_ref,
                'files' => $c->files->map(fn ($f) => [
                    'path' => $f->path,
                    'status' => $f->status,
                ])->all(),
            ])->all(),
            'intro' => $review->intro,
            'url' => route('reviews.show', $review),
            'assignee' => $review->assignee?->name,
            'coverage' => $review->coverage(),
            'tasks' => $review->tasks->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
            ])->all(),
            'sections' => $review->sections->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'title' => $s->title,
                'mode' => $s->mode,
                'context' => $s->context,
                'link' => $s->link,
                'checks' => $s->checks,
                'status' => $s->status,
                'note' => $s->note,
                'files' => $s->files->pluck('path')->all(),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->integer()->description('The review to read.')->required(),
        ];
    }
}
