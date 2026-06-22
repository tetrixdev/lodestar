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

#[Description('Read one project in full by id or slug: its name/slug/primary_goal/description, its linked repositories, deliverable counts by board phase, in-flight task counts by board phase, and the most-recent work sessions. Access-scoped to your projects. The single-project overview behind list_projects.')]
#[Name('get_project')]
class GetProjectTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required'],
            'sessions' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $project = $this->ownedProject($request, $data['project']);
        if (! $project) {
            return Response::error('No project of yours matches '.json_encode($data['project']).'.');
        }

        $project->load('repositories:id,full_name,default_branch');

        $sessionLimit = (int) ($data['sessions'] ?? 5);
        $sessions = $project->workSessions()
            ->with('task:id,title')
            ->latest('occurred_on')->latest('id')
            ->limit($sessionLimit)
            ->get();

        return Response::json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'primary_goal' => $project->primary_goal,
                'description' => $project->description,
                'repositories' => $project->repositories->map(fn ($r) => [
                    'full_name' => $r->full_name,
                    'default_branch' => $r->default_branch,
                ])->all(),
                'deliverable_counts' => $this->deliverableCountsByPhase($project),
                'task_counts' => $this->taskCountsByPhase($project),
                'recent_sessions' => $sessions->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'occurred_on' => optional($s->occurred_on)->toDateString(),
                    'task' => $s->task ? ['id' => $s->task->id, 'title' => $s->task->title] : null,
                ])->all(),
            ],
        ]);
    }

    /** Tally deliverables by board phase column. */
    private function deliverableCountsByPhase($project): array
    {
        $counts = ['backlog' => 0, 'plan' => 0, 'build' => 0, 'review' => 0, 'ship' => 0, 'total' => 0];
        foreach ($project->deliverables()->get(['id', 'status']) as $d) {
            $phase = Deliverable::PHASE_COLUMN[$d->status] ?? 'backlog';
            $counts[$phase]++;
            $counts['total']++;
        }

        return $counts;
    }

    /** Tally in-flight tasks by board phase column. */
    private function taskCountsByPhase($project): array
    {
        $counts = ['backlog' => 0, 'plan' => 0, 'build' => 0, 'review' => 0, 'ship' => 0, 'total' => 0];
        foreach ($project->tasks()->get(['id', 'status']) as $t) {
            foreach (Task::PHASES as $phase => $def) {
                if (in_array($t->status, $def['statuses'], true)) {
                    $counts[$phase]++;
                    $counts['total']++;
                    break;
                }
            }
        }

        return $counts;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project to read, by numeric id or slug.')->required(),
            'sessions' => $schema->integer()->description('How many recent work sessions to include. Default 5.'),
        ];
    }
}
