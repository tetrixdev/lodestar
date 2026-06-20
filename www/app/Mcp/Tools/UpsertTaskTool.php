<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update a task (kanban card) on one of your projects. Omit id to create; pass id to update content. Write the body and plan first, THEN pick the entry status — a card created with a plan defaults into the plan_review gate, a bare idea into new. Lifecycle moves after creation go through advance_task, not here — status is only honoured on create.')]
#[Name('upsert_task')]
class UpsertTaskTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required_without_all:id,deliverable', 'string'],
            'id' => ['nullable', 'integer'],
            'deliverable' => ['nullable', 'integer'],
            'depends_on' => ['nullable', 'array'],
            'depends_on.*' => ['integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // A summary is the scannable default the board/card shows; it is
            // mandatory whenever its long-form detail is set, so detail never
            // ships without a TL;DR. (Not applied retroactively to old rows.)
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'plan' => ['nullable', 'string'],
            'plan_summary' => ['nullable', 'string', 'required_with:plan'],
            // Where a new card enters. A card with a plan is review-ready, so it may
            // also enter at the plan_review gate (the default when a plan is given) or,
            // if you want to skip the gate, straight at ready_for_dev. Working (*-ing)
            // states are never seedable — those are reached only by claim_task.
            'status' => ['nullable', 'string', 'in:'.implode(',', [
                Task::STATUS_NEW,
                Task::STATUS_READY_FOR_PLANNING,
                Task::STATUS_PLAN_REVIEW,
                Task::STATUS_READY_FOR_DEV,
            ])],
        ]);

        // A plan-gated entry state needs an actual plan to gate on / build from.
        $hasPlan = ! empty($data['plan'] ?? null);
        $planGated = [Task::STATUS_PLAN_REVIEW, Task::STATUS_READY_FOR_DEV];
        if (in_array($data['status'] ?? null, $planGated, true) && ! $hasPlan) {
            return Response::error('A task can only enter at plan_review or ready_for_dev when it carries a plan — pass `plan` (and `plan_summary`), or use new / ready_for_planning.');
        }

        // Optional deliverable attachment — a child task is decomposed from a
        // deliverable's plan, so it skips planning (the model coerces it to
        // ready_for_dev) and gets a per-deliverable sub_id automatically.
        $deliverable = null;
        if (! empty($data['deliverable'])) {
            $deliverable = Deliverable::query()
                ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
                ->whereKey((int) $data['deliverable'])
                ->first();
            if (! $deliverable) {
                return Response::error('No deliverable with that id belongs to you.');
            }
        }

        if (! empty($data['id'])) {
            $task = $this->ownedTask($request, (int) $data['id']);
            if (! $task) {
                return Response::error('No task with that id belongs to you.');
            }
            if ($deliverable) {
                $task->deliverable_id = $deliverable->id;
            }
        } else {
            // A child task lives in its deliverable's project.
            $project = $deliverable?->project ?? $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
            // Default the entry state from the work itself: a card that already
            // has a plan is ready for the human's plan_review; a bare idea is new.
            // (A deliverable child is coerced to ready_for_dev by the model.)
            $status = $data['status'] ?? ($hasPlan ? Task::STATUS_PLAN_REVIEW : Task::STATUS_NEW);
            $task = $project->tasks()->make([
                'deliverable_id' => $deliverable?->id,
                'status' => $status,
                'position' => (int) $project->tasks()->where('status', $status)->max('position') + 1,
            ]);
        }

        foreach (['title', 'category', 'body', 'body_summary', 'plan', 'plan_summary'] as $field) {
            if (array_key_exists($field, $data)) {
                $task->{$field} = $data[$field];
            }
        }
        $task->save();

        // Wire dependencies (only tasks in the same project; the pair is unique).
        if (! empty($data['depends_on'])) {
            $deps = Task::query()
                ->where('project_id', $task->project_id)
                ->whereIn('id', $data['depends_on'])
                ->where('id', '!=', $task->id)
                ->pluck('id');
            $task->dependencies()->syncWithoutDetaching($deps);
        }

        return Response::json([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'created' => $task->wasRecentlyCreated,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (required when creating; ignored if `deliverable` is given — a child lives in its deliverable\'s project).'),
            'id' => $schema->integer()->description('Existing task id to update. Omit to create.'),
            'deliverable' => $schema->integer()->description('Attach this task to a deliverable as a child. Child tasks skip planning (enter at ready_for_dev) and get a per-deliverable sub_id automatically.'),
            'depends_on' => $schema->array()->items($schema->integer())->description('Task ids this task is blocked by (same project). The loop will not hand it out until they are done.'),
            'title' => $schema->string()->description('Card title (required when creating).'),
            'category' => $schema->string()->description('Optional grouping prefix, e.g. "mcp", "infra".'),
            'body' => $schema->string()->description('Full markdown card detail — the human-readable spec for this work. Structure it skimmably under short headers: **Why** (the goal / problem this solves), **What** (scope — what is in, and what is explicitly out), and **Done when** (acceptance — how we know it works). Link related cards by #id. Keep it tight; the plan is where the file-by-file structure map goes, not here. If you set this you MUST also pass body_summary.'),
            'body_summary' => $schema->string()->description('Required whenever body is set: a 1–2 sentence scannable TL;DR of the card, shown by default (the full body opens on demand).'),
            'plan' => $schema->string()->description('The planning artifact (markdown). If you set this you MUST also pass plan_summary. Write this BEFORE choosing status — a card that has a plan defaults to the plan_review gate.'),
            'plan_summary' => $schema->string()->description('Required whenever plan is set: a 1–2 sentence scannable TL;DR of the plan.'),
            // Listed last on purpose: decide the entry state AFTER writing the body/plan.
            'status' => $schema->string()->enum([
                Task::STATUS_NEW,
                Task::STATUS_READY_FOR_PLANNING,
                Task::STATUS_PLAN_REVIEW,
                Task::STATUS_READY_FOR_DEV,
            ])->description('Entry state on create (decide this LAST, after the body/plan). Defaults: a card WITH a plan starts at "plan_review" (human approves the plan); a bare idea starts at "new". Override with: "new" (loose thought, no plan), "ready_for_planning" (queue an agent to plan it), "plan_review" (plan written, await human approval), or "ready_for_dev" (plan written AND you want to skip the gate — straight to build). plan_review and ready_for_dev require a plan. Ignored on update — use advance_task to move a card.'),
        ];
    }
}
