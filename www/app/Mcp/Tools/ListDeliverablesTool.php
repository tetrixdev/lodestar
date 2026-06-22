<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('List the deliverables of one of your projects as COMPACT rows (id, title, status, phase, base_branch, branch, task counts by phase) — NOT the concept/body/plan (use get_deliverable for those). Filter by status and phase. Access-scoped to your projects.')]
#[Name('list_deliverables')]
class ListDeliverablesTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required'],
            'status' => ['nullable'],
            'phase' => ['nullable', 'string'],
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project of yours matches '.json_encode($data['project']).'.');
        }

        $query = $project->deliverables()
            ->with('tasks:id,deliverable_id,status');

        if (! empty($data['status'])) {
            $statuses = is_array($data['status']) ? $data['status'] : [$data['status']];
            $query->whereIn('status', $statuses);
        }

        if (! empty($data['phase'])) {
            $phase = $data['phase'];
            $statusesInPhase = array_keys(array_filter(
                Deliverable::PHASE_COLUMN,
                fn (string $col) => $col === $phase,
            ));
            $query->whereIn('status', $statusesInPhase);
        }

        $deliverables = $query->orderBy('id')->get();

        return Response::json([
            'deliverables' => $deliverables->map(fn (Deliverable $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'status' => $d->status,
                'phase' => $d->phaseColumn(),
                'base_branch' => $d->base_branch,
                'branch' => $d->branch,
                'task_counts' => $this->taskCountsByPhase($d),
            ])->all(),
        ]);
    }

    /** Tally child tasks by board phase (backlog/plan/build/review/ship), plus a total. */
    private function taskCountsByPhase(Deliverable $deliverable): array
    {
        $counts = ['backlog' => 0, 'plan' => 0, 'build' => 0, 'review' => 0, 'ship' => 0];
        foreach ($deliverable->tasks as $task) {
            foreach (\App\Models\Task::PHASES as $phase => $def) {
                if (in_array($task->status, $def['statuses'], true)) {
                    $counts[$phase]++;
                    break;
                }
            }
        }
        $counts['total'] = $deliverable->tasks->count();

        return $counts;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project to list deliverables for, by numeric id or slug.')->required(),
            'status' => $schema->array()->items($schema->string())->description('Limit to one or more deliverable statuses.'),
            'phase' => $schema->string()->description('Limit to one board phase column: backlog | plan | build | review | ship.'),
        ];
    }
}
