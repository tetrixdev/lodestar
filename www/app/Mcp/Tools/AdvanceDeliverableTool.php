<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use App\Models\Review;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Move a deliverable along a LEGAL funnel transition (illegal jumps rejected). Gates enforced server-side: entering building stamps the integration branch; leaving building for AI review needs all child tasks done/cancelled. The claim is cleared when the deliverable leaves a working state. (Open questions are now plan-review findings on each task, not a deliverable gate.)')]
#[Name('advance_deliverable')]
class AdvanceDeliverableTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'deliverable_id' => ['required', 'integer'],
            'to' => ['required', 'string', 'in:'.implode(',', array_merge(Deliverable::STATUSES, [Deliverable::STATUS_CANCELLED]))],
        ]);

        $deliverable = Deliverable::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey((int) $data['deliverable_id'])
            ->first();
        if (! $deliverable) {
            return Response::error('No deliverable with that id belongs to you.');
        }

        if ($deliverable->status === Deliverable::STATUS_CANCELLED) {
            return Response::error('Cancelled deliverables are archived. Create a new one instead of restoring this.');
        }

        $to = $data['to'];
        if (! $deliverable->canTransitionTo($to)) {
            return Response::error(
                "Illegal transition: {$deliverable->status} → {$to}. Allowed from {$deliverable->status}: "
                .implode(', ', $deliverable->allowedTransitions()).'.'
            );
        }

        // (The deliverable-level open-questions gate is retired — open questions now
        // live as findings on each task's plan review.)

        // All child work must be merged before the deliverable-level review.
        if ($deliverable->status === Deliverable::STATUS_BUILDING
            && $to === Deliverable::STATUS_READY_FOR_AI_REVIEW
            && ! $deliverable->allTasksComplete()) {
            return Response::error('Not all child tasks are done — finish (or cancel) every task before the deliverable AI review.');
        }

        // Coverage gate: the deliverable can only reach the human architecture
        // review once it has a deliverable-scoped review whose every changed file
        // is covered by a section (the same exhaustiveness guard as tasks — this is
        // what forces newly-added files into sections on a re-review).
        if ($to === Deliverable::STATUS_HUMAN_ARCHITECTURE_REVIEW) {
            $reviews = $deliverable->reviews()->where('scope', Review::SCOPE_DELIVERABLE)->get();
            if ($reviews->isEmpty()) {
                return Response::error('This deliverable has no linked review. Create one (create_review scope:"deliverable") and cover its files before the human review.');
            }
            foreach ($reviews as $review) {
                $coverage = $review->coverage();
                if (! $coverage['complete']) {
                    return Response::error("Review #{$review->id} still has uncovered files: ".implode(', ', $coverage['uncovered']).'.');
                }
            }
        }

        // Entering build: cut the integration branch identity if not set yet.
        if ($to === Deliverable::STATUS_BUILDING) {
            if (! $deliverable->branch) {
                $deliverable->branch = $deliverable->branchName();
            }
            if (! $deliverable->base_branch) {
                $deliverable->base_branch = 'main';
            }
        }

        $deliverable->status = $to;
        $deliverable->position = (int) $deliverable->project->deliverables()
            ->where('status', $to)->max('position') + 1;

        if (! in_array($to, Deliverable::workingStatuses(), true)) {
            $deliverable->claimed_by = null;
            $deliverable->claimed_at = null;
        }
        $deliverable->save();

        return Response::json([
            'id' => $deliverable->id,
            'status' => $deliverable->status,
            'branch' => $deliverable->branch,
            'base_branch' => $deliverable->base_branch,
            'allowed_next' => $deliverable->allowedTransitions(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'deliverable_id' => $schema->integer()->description('The deliverable to move.')->required(),
            'to' => $schema->string()->description('Target status. Must be a legal transition from the current status.')->required(),
        ];
    }
}
