<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Read one or more tasks by id (batch). Returns full contents — title, body, plan, status, branch, rework notes, deliverable membership, dependencies/dependents and recent activity. Access-scoped to your projects; unknown/forbidden ids are reported in `missing` (partial success). The board can create/move tasks but this is how an agent reads them back.')]
#[Name('get_task')]
class GetTaskTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['task_ids'])));

        $tasks = Task::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereIn('id', $ids)
            ->with(['project:id,name,slug', 'deliverable:id,title,status,branch,base_branch,comparison_ref', 'dependencies:id,title,status', 'dependents:id,title,status', 'reviews:id,title,status,outcome'])
            ->get()
            ->keyBy('id');

        $found = [];
        foreach ($ids as $id) {
            $task = $tasks->get($id);
            if (! $task) {
                continue;
            }

            $found[] = [
                'id' => $task->id,
                'project' => ['id' => $task->project->id, 'name' => $task->project->name, 'slug' => $task->project->slug],
                'title' => $task->title,
                'category' => $task->category,
                'status' => $task->status,
                'phase' => Task::phaseFor($task->status),
                'priority' => $task->priority,
                'branch' => $task->branch,
                'is_deliverable_child' => $task->isDeliverableChild(),
                'human_gate' => $task->humanGateType(),
                'deliverable' => $task->deliverable ? [
                    'id' => $task->deliverable->id,
                    'sub_id' => $task->sub_id,
                    'title' => $task->deliverable->title,
                    'status' => $task->deliverable->status,
                    'branch' => $task->deliverable->branch,
                    'base_branch' => $task->deliverable->base_branch,
                ] : null,
                'is_corrective' => $task->is_corrective,
                'needs_functional_review' => $task->needs_functional_review,
                'blocked' => $task->isBlocked(),
                'dependencies' => $task->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->all(),
                'dependents' => $task->dependents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->all(),
                'reviews' => $task->reviews->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'status' => $r->status, 'outcome' => $r->outcome])->all(),
                'body' => $task->body,
                'plan' => $task->plan,
                'rework_notes' => $task->rework_notes,
                'allowed_next' => $task->allowedTransitions(),
            ];
        }

        $missing = array_values(array_diff($ids, $tasks->keys()->all()));

        return Response::json(['tasks' => $found, 'missing' => $missing]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_ids' => $schema->array()->items($schema->integer())->description('Task ids to read (batch). Unknown or inaccessible ids come back in `missing`.'),
        ];
    }
}
