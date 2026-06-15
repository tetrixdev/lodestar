<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update a task (kanban card) on one of your projects. Omit id to create; pass id to update content. Lifecycle moves go through advance_task, not here — status is only honoured on create.')]
#[Name('upsert_task')]
class UpsertTaskTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required_without:id', 'string'],
            'id' => ['nullable', 'integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // A summary is the scannable default the board/card shows; it is
            // mandatory whenever its long-form detail is set, so detail never
            // ships without a TL;DR. (Not applied retroactively to old rows.)
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'plan' => ['nullable', 'string'],
            'plan_summary' => ['nullable', 'string', 'required_with:plan'],
            // A new card may only enter at a backlog state — never mid-lifecycle,
            // a working (*-ing) state, or a human gate. Moves go through advance_task.
            'status' => ['nullable', 'string', 'in:'.Task::STATUS_NEW.','.Task::STATUS_READY_FOR_PLANNING],
        ]);

        if (! empty($data['id'])) {
            $task = $this->ownedTask($request, (int) $data['id']);
            if (! $task) {
                return Response::error('No task with that id belongs to you.');
            }
        } else {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
            $status = $data['status'] ?? Task::STATUS_NEW;
            $task = $project->tasks()->make([
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
            'project' => $schema->string()->description('Project id or slug (required when creating).'),
            'id' => $schema->integer()->description('Existing task id to update. Omit to create.'),
            'title' => $schema->string()->description('Card title (required when creating).'),
            'category' => $schema->string()->description('Optional grouping prefix, e.g. "mcp", "infra".'),
            'body' => $schema->string()->description('Full markdown card detail. If you set this you MUST also pass body_summary.'),
            'body_summary' => $schema->string()->description('Required whenever body is set: a 1–2 sentence scannable TL;DR of the card, shown by default (the full body opens on demand).'),
            'plan' => $schema->string()->description('The planning artifact (markdown). If you set this you MUST also pass plan_summary.'),
            'plan_summary' => $schema->string()->description('Required whenever plan is set: a 1–2 sentence scannable TL;DR of the plan.'),
            'status' => $schema->string()->enum([Task::STATUS_NEW, Task::STATUS_READY_FOR_PLANNING])->description('Initial backlog status on create: "new" (default) or "ready_for_planning". Ignored on update — use advance_task to move a card.'),
        ];
    }
}
