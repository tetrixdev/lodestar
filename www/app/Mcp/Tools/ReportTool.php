<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Log a work-session recording what an agent did on a task. Reference the task by task_id (its project is used) or pass a project directly. This is how the loop reports progress back to the board.')]
#[Name('report')]
class ReportTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required_without:project', 'integer'],
            'project' => ['required_without:task_id', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        if (! empty($data['task_id'])) {
            $task = $this->ownedTask($request, (int) $data['task_id']);
            if (! $task) {
                return Response::error('No task with that id belongs to you.');
            }
            $project = $task->project;
        } else {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
        }

        $session = $project->workSessions()->create([
            'title' => $data['title'],
            'slug' => Str::slug($data['title']).'-'.now()->format('Ymd-His').'-'.Str::random(4),
            'body' => $data['body'] ?? null,
            'occurred_on' => $data['occurred_on'] ?? now()->toDateString(),
        ]);

        return Response::json([
            'id' => $session->id,
            'project_id' => $project->id,
            'logged' => true,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Task whose project the session is logged on.'),
            'project' => $schema->string()->description('Project id or slug (alternative to task_id).'),
            'title' => $schema->string()->description('Short summary line of what was done.')->required(),
            'body' => $schema->string()->description('Optional markdown detail.'),
            'occurred_on' => $schema->string()->description('Date the work happened (YYYY-MM-DD). Defaults to today.'),
        ];
    }
}
