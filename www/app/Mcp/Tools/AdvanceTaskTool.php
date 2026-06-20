<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Move a task to a new status along a LEGAL transition. The server rejects illegal jumps, so an agent can only follow the lifecycle. The claim is cleared once the task leaves its working state. Use this to hand a card back to a queue or to a human gate.')]
#[Name('advance_task')]
class AdvanceTaskTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'to' => ['required', 'string', 'in:'.implode(',', array_merge(Task::STATUSES, [Task::STATUS_CANCELLED]))],
        ]);

        $task = $this->ownedTask($request, (int) $data['task_id']);
        if (! $task) {
            return Response::error('No task with that id belongs to you.');
        }

        // Restoring a cancelled card is human-only: an agent that wants the work
        // back creates a fresh task (upsert_task) instead, preserving the archive.
        if ($task->status === Task::STATUS_CANCELLED) {
            return Response::error('Cancelled tasks are archived. Create a new task instead of restoring this one.');
        }

        if (! $task->canTransitionTo($data['to'])) {
            return Response::error(
                "Illegal transition: {$task->status} → {$data['to']}. Allowed from {$task->status}: "
                .implode(', ', $task->allowedTransitions()).'.'
            );
        }

        // Coverage gate: a card can only reach a human reviewer once it has at
        // least one linked review AND every linked review accounts for every
        // changed file. This is the hard, server-side exhaustiveness guard — the
        // AI cannot hand off with a missing or gappy review.
        if ($data['to'] === Task::STATUS_HUMAN_REVIEW) {
            $reviews = $task->reviews;
            if ($reviews->isEmpty()) {
                return Response::error(
                    'This task has no linked review. Create one (create_review with task_ids) and cover its files before handing off to a human.'
                );
            }
            foreach ($reviews as $review) {
                // ≥1 diff per review: a review with no comparison can't be handed
                // off (no actual change was reviewed).
                if (! $review->hasComparison()) {
                    return Response::error(
                        "Review #{$review->id} has no comparison — a review must compare at least one repo "
                        .'(create it with comparisons / repo+base_ref+head_ref) before handing off to a human.'
                    );
                }
                $coverage = $review->coverage();
                if (! $coverage['complete']) {
                    return Response::error(
                        "Review #{$review->id} still has uncovered files; allocate them to a section first: "
                        .implode(', ', $coverage['uncovered']).'.'
                    );
                }
            }
        }

        $task->status = $data['to'];
        // Lands at the bottom of the target status, like the board's lifecycle move.
        $task->position = (int) Task::where('project_id', $task->project_id)
            ->where('status', $task->status)->max('position') + 1;

        // The claim belongs to the working state; releasing it once the card moves
        // on keeps "who holds this" honest (status_changed_at is stamped by the hook).
        if (! in_array($task->status, Task::workingStatuses(), true)) {
            $task->claimed_by = null;
            $task->claimed_at = null;
        }
        $task->save();

        // Freeze linked reviews at hand-off so coverage can't silently re-open
        // after the gate passed (a draft review is editable; an in_review one is
        // locked against further AI section/file edits — see upsert_review_section).
        if ($task->status === Task::STATUS_HUMAN_REVIEW) {
            $task->reviews()->where('status', 'draft')->update(['status' => 'in_review']);
        }

        return Response::json([
            'id' => $task->id,
            'status' => $task->status,
            'allowed_next' => $task->allowedTransitions(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to move.')->required(),
            'to' => $schema->string()->description('Target status. Must be a legal transition from the current status.')->required(),
        ];
    }
}
