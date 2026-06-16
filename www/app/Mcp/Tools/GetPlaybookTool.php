<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Playbook;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Get the composed playbook (prompt) to run. Pass a claimed task_id (its working state selects the phase), or a phase directly — "main" (the bootstrap playbook: load this first), or plan / develop / ai_review / merge — or a named "key" for an on-demand playbook. Phase playbooks are COMPOSED across scopes (system → team → personal → project); named playbooks resolve to the most-specific scope. Returns the composed body and which layers contributed. Always fetch fresh — playbooks update server-side.')]
#[Name('get_playbook')]
class GetPlaybookTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required_without_all:phase,key', 'integer'],
            'phase' => ['required_without_all:task_id,key', 'string', 'in:'.implode(',', Playbook::PHASES)],
            'key' => ['required_without_all:task_id,phase', 'string', 'max:255'],
            'project' => ['nullable', 'string'],
        ]);

        $user = $this->currentUser($request);
        $project = null;
        $key = $data['phase'] ?? $data['key'] ?? null;

        if (! empty($data['task_id'])) {
            $task = $this->ownedTask($request, (int) $data['task_id']);
            if (! $task) {
                return Response::error('No task with that id belongs to you.');
            }
            $key = Task::phaseFor($task->status);
            if (! $key) {
                return Response::error(
                    "Task {$task->id} is in '{$task->status}', which has no playbook phase. "
                    .'Claim a ready_* task first, or pass phase explicitly.'
                );
            }
            $project = $task->project;
        } elseif (! empty($data['project'])) {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
        }

        // Phase keys compose across scopes; any other key resolves to one scope.
        if (Playbook::isPhase($key)) {
            $composed = Playbook::compose($user, $project, $key);
            if ($composed['layers'] === []) {
                return Response::error("No playbook is available for the '{$key}' phase.");
            }

            return Response::json([
                'key' => $key,
                'composed' => true,
                'body' => $composed['body'],
                // Each layer carries its playbook_id + hash — the base_hash you must
                // pass to propose_playbook_change to edit that layer.
                'layers' => $composed['layers'],
            ]);
        }

        $version = Playbook::resolveNamed($user, $project, $key);
        if (! $version) {
            return Response::error("No playbook named '{$key}' is available.");
        }

        return Response::json([
            'key' => $key,
            'composed' => false,
            'scope' => $version->playbook->scope,
            'playbook_id' => $version->playbook->id,
            'version' => $version->version,
            'title' => $version->title,
            'body' => $version->body,
            // The base_hash to pass to propose_playbook_change when editing this layer.
            'hash' => $version->playbook->currentHash(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('A claimed task; its working state selects the phase.'),
            'phase' => $schema->string()->enum(Playbook::PHASES)->description('Phase to load directly (composed across scopes).'),
            'key' => $schema->string()->description('A named on-demand playbook key (resolves to the most-specific scope, no composition).'),
            'project' => $schema->string()->description('Optional project id/slug for scope resolution (team/personal/project layers).'),
        ];
    }
}
