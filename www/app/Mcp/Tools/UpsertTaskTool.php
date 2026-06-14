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

        foreach (['title', 'category', 'body'] as $field) {
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
            'body' => $schema->string()->description('Optional markdown card detail.'),
            'status' => $schema->string()->enum([Task::STATUS_NEW, Task::STATUS_READY_FOR_PLANNING])->description('Initial backlog status on create: "new" (default) or "ready_for_planning". Ignored on update — use advance_task to move a card.'),
        ];
    }
}
