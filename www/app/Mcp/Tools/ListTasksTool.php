<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('List tasks across your projects as COMPACT board rows (id, sub_id, title, status, phase, category, priority, deliverable, blocked, human_gate) — NOT the full body/plan (use get_task for those). Filter by project (id/slug), deliverable (id), status (one or many) and phase. Access-scoped to your projects. The board navigator: scan what exists, then read the ones you care about.')]
#[Name('list_tasks')]
class ListTasksTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['nullable'],
            'deliverable' => ['nullable', 'integer'],
            'status' => ['nullable'],
            'phase' => ['nullable', 'string'],
        ]);

        $user = $this->currentUser($request);

        $query = Task::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($user))
            ->with('deliverable:id,title');

        if (! empty($data['project'])) {
            $ref = $data['project'];
            $query->whereHas('project', fn ($q) => $q->where(is_numeric($ref) ? 'id' : 'slug', $ref));
        }

        if (! empty($data['deliverable'])) {
            $query->where('deliverable_id', (int) $data['deliverable']);
        }

        if (! empty($data['status'])) {
            $statuses = is_array($data['status']) ? $data['status'] : [$data['status']];
            $query->whereIn('status', $statuses);
        }

        // Phase filters by the board's phase column: the working *-ing phase a status
        // runs (Task::phaseFor), and — since most statuses are not *-ing — the static
        // PHASES grouping a status sits in.
        if (! empty($data['phase'])) {
            $phase = $data['phase'];
            $statusesInPhase = Task::PHASES[$phase]['statuses'] ?? [];
            $query->whereIn('status', $statusesInPhase);
        }

        // Order by deliverable then by board position within a status.
        $tasks = $query->orderBy('deliverable_id')->orderBy('position')->orderBy('id')->get();

        return Response::json([
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'sub_id' => $t->sub_id,
                'title' => $t->title,
                'status' => $t->status,
                'phase' => Task::phaseFor($t->status),
                'category' => $t->category,
                'priority' => $t->priority,
                'deliverable' => $t->deliverable ? [
                    'id' => $t->deliverable->id,
                    'title' => $t->deliverable->title,
                ] : null,
                'blocked' => $t->isBlocked(),
                'human_gate' => $t->humanGateType(),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Limit to one project, by numeric id or slug.'),
            'deliverable' => $schema->integer()->description('Limit to one deliverable, by id.'),
            'status' => $schema->array()->items($schema->string())->description('Limit to one or more lifecycle statuses (e.g. ["developing","ready_for_dev"]).'),
            'phase' => $schema->string()->description('Limit to one board phase column: backlog | plan | build | review | ship.'),
        ];
    }
}
