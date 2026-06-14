<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\ReviewSection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Add or update one section (step) of a review walkthrough. Omit id to append a section; pass id to update one. mode is one of: skip, behavioural, direct, direct_doc, mirror_guard.')]
#[Name('upsert_review_section')]
class UpsertReviewSectionTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'review_id' => ['required', 'integer'],
            'id' => ['nullable', 'integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'mode' => ['required_without:id', 'string', 'in:'.implode(',', ReviewSection::MODES)],
            'position' => ['nullable', 'integer'],
            'context' => ['nullable', 'string'],
            'link' => ['nullable', 'string', 'max:255'],
            'checks' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:open,signed_off'],
            'note' => ['nullable', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string'],
        ]);

        $review = $this->ownedReview($request, (int) $data['review_id']);
        if (! $review) {
            return Response::error('No review with that id belongs to you.');
        }

        // Once a review has been handed to a human (in_review / done) its coverage
        // is frozen — editing sections could re-open a gap behind the reviewer's
        // back. Only draft reviews are editable by the agent.
        if ($review->status !== 'draft') {
            return Response::error("Review #{$review->id} is locked (status: {$review->status}); it has been handed off and can no longer be edited.");
        }

        if (! empty($data['id'])) {
            $section = $review->sections()->whereKey((int) $data['id'])->first();
            if (! $section) {
                return Response::error('No section with that id in this review.');
            }
        } else {
            $section = $review->sections()->make([
                'status' => 'open',
                'position' => (int) $review->sections()->max('position') + 1,
            ]);
        }

        foreach (['title', 'mode', 'position', 'context', 'link', 'checks', 'status', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $section->{$field} = $data[$field];
            }
        }
        $section->save();

        // Allocate changed files to this section. Every path must belong to the
        // review's comparison — an unknown path is rejected (the AI can't invent
        // coverage for a file GitHub didn't report).
        if (array_key_exists('files', $data)) {
            $matched = $review->files()->whereIn('path', $data['files'])->get();
            $unknown = array_diff($data['files'], $matched->pluck('path')->all());
            if ($unknown !== []) {
                return Response::error('These files are not in this review\'s comparison: '.implode(', ', $unknown));
            }
            $section->files()->sync($matched->pluck('id'));
        }

        $coverage = $review->coverage();

        return Response::json([
            'id' => $section->id,
            'review_id' => $review->id,
            'position' => $section->position,
            'created' => $section->wasRecentlyCreated,
            'coverage' => $coverage,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->integer()->description('The review to add the section to.')->required(),
            'id' => $schema->integer()->description('Existing section id to update. Omit to append.'),
            'title' => $schema->string()->description('Section title (required when creating).'),
            'mode' => $schema->string()->enum(ReviewSection::MODES)->description('Review mode (required when creating).'),
            'position' => $schema->integer()->description('Order in the walkthrough. Defaults to the end.'),
            'context' => $schema->string()->description('Prose that rebuilds the reviewer\'s context for this step.'),
            'link' => $schema->string()->description('What to open — a doc, file path, or route.'),
            'checks' => $schema->array()->description('List of "what to confirm" strings.'),
            'status' => $schema->string()->enum(['open', 'signed_off'])->description('Sign-off state (humans set this in the UI; usually leave as open).'),
            'note' => $schema->string()->description('Optional note / change request.'),
            'files' => $schema->array()->description('Changed-file paths this section covers (must be paths from the review\'s comparison). Replaces the section\'s current set.'),
        ];
    }
}
