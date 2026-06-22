<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\WorkSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('List the work-session log of one of your projects, newest first: id, title, occurred_on, and the task it reports on (if any). Optionally limit to one task. Access-scoped to your projects. The running history of what was done.')]
#[Name('list_sessions')]
class ListSessionsTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required'],
            'task' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project of yours matches '.json_encode($data['project']).'.');
        }

        $limit = (int) ($data['limit'] ?? 20);

        $query = $project->workSessions()
            ->with('task:id,title')
            ->latest('occurred_on')
            ->latest('id');

        if (! empty($data['task'])) {
            $query->where('task_id', (int) $data['task']);
        }

        $sessions = $query->limit($limit)->get();

        return Response::json([
            'sessions' => $sessions->map(fn (WorkSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'occurred_on' => optional($s->occurred_on)->toDateString(),
                'task' => $s->task ? ['id' => $s->task->id, 'title' => $s->task->title] : null,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project whose sessions to list, by numeric id or slug.')->required(),
            'task' => $schema->integer()->description('Limit to sessions reporting on one task, by id.'),
            'limit' => $schema->integer()->description('Max sessions to return (newest first). Default 20.'),
        ];
    }
}
