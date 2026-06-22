<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Read one deliverable in full by id: title, concept/body, status/phase, branch and base_branch, its child tasks (compact rows) and its reviews. (Open questions now live as findings on each task\'s plan review.) Access-scoped to your projects. The deliverable counterpart to get_task.')]
#[Name('get_deliverable')]
class GetDeliverableTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'deliverable_id' => ['required', 'integer'],
        ]);

        $deliverable = Deliverable::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey((int) $data['deliverable_id'])
            ->with([
                'project:id,name,slug',
                'tasks',
                'reviews:id,deliverable_id,title,scope,review_type,status,outcome',
            ])
            ->first();

        if (! $deliverable) {
            return Response::error('No deliverable with that id belongs to you.');
        }

        return Response::json([
            'deliverable' => [
                'id' => $deliverable->id,
                'project' => [
                    'id' => $deliverable->project->id,
                    'name' => $deliverable->project->name,
                    'slug' => $deliverable->project->slug,
                ],
                'title' => $deliverable->title,
                'status' => $deliverable->status,
                'phase' => $deliverable->phaseColumn(),
                'branch' => $deliverable->branch,
                'base_branch' => $deliverable->base_branch,
                'concept' => $deliverable->concept,
                'body' => $deliverable->body,
                'tasks' => $deliverable->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'sub_id' => $t->sub_id,
                    'title' => $t->title,
                    'status' => $t->status,
                    'phase' => \App\Models\Task::phaseFor($t->status),
                    'category' => $t->category,
                    'priority' => $t->priority,
                    'blocked' => $t->isBlocked(),
                ])->all(),
                'reviews' => $deliverable->reviews->map(fn ($r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'scope' => $r->scope,
                    'type' => $r->review_type,
                    'status' => $r->status,
                    'outcome' => $r->outcome,
                ])->all(),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'deliverable_id' => $schema->integer()->description('The deliverable to read, by id.')->required(),
        ];
    }
}
