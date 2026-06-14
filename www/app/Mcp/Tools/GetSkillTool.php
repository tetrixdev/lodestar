<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Skill;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Get the skill (prompt) for a phase. Pass a claimed task_id and the phase is taken from its working state; or pass phase directly — "main" (the bootstrap skill: load this first), or plan / develop / ai_review / merge. Returns your bound skill if you have one, else the current system skill. Always fetch this fresh — skills update server-side.')]
#[Name('get_skill')]
class GetSkillTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required_without:phase', 'integer'],
            'phase' => ['required_without:task_id', 'string', 'in:'.implode(',', Skill::PHASES)],
            'project' => ['nullable', 'string'],
        ]);

        $project = null;

        if (! empty($data['task_id'])) {
            $task = $this->ownedTask($request, (int) $data['task_id']);
            if (! $task) {
                return Response::error('No task with that id belongs to you.');
            }
            $phase = Task::phaseFor($task->status);
            if (! $phase) {
                return Response::error(
                    "Task {$task->id} is in '{$task->status}', which has no skill phase. "
                    .'Claim a ready_* task first, or pass phase explicitly.'
                );
            }
            $project = $task->project;
        } else {
            $phase = $data['phase'];
            if (! empty($data['project'])) {
                $project = $this->ownedProject($request, $data['project']);
                if (! $project) {
                    return Response::error('No project "'.$data['project'].'" belongs to you.');
                }
            }
        }

        $skill = Skill::resolve($this->currentUser($request), $project, $phase);
        if (! $skill) {
            return Response::error("No skill is available for the '{$phase}' phase.");
        }

        return Response::json([
            'phase' => $phase,
            'skill_id' => $skill->id,
            'kind' => $skill->kind,
            'version' => $skill->version,
            'title' => $skill->title,
            'body' => $skill->body,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('A claimed task; its working state selects the phase.'),
            'phase' => $schema->string()->enum(Skill::PHASES)->description('Phase to load directly (alternative to task_id).'),
            'project' => $schema->string()->description('Optional project id/slug for per-project binding resolution.'),
        ];
    }
}
