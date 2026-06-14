<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Atomically claim the next queued (ready_*) task and flip it to its working (*-ing) state. This is the only way to start work on a card. Optionally restrict to one project and/or phase. Returns the claimed task and the phase to load a skill for, or "no task available".')]
#[Name('claim_task')]
class ClaimTaskTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['nullable', 'string'],
            'phase' => ['nullable', 'string', 'in:'.implode(',', Task::PHASE_FOR_WORKING)],
            'agent_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->currentUser($request);

        // Scope to the caller's projects (optionally one named project).
        if (! empty($data['project'])) {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
            $projectIds = [$project->id];
        } else {
            $projectIds = $user->projects()->pluck('id')->all();
        }

        // Which queue states are eligible — all four, or the one for a phase.
        $claimable = Task::claimableStatuses();
        if (! empty($data['phase'])) {
            $working = array_search($data['phase'], Task::PHASE_FOR_WORKING, true);
            $claimable = [Task::queueStateFor($working)];
        }

        $agent = $data['agent_id']
            ?? $user->currentAccessToken()?->name
            ?? 'agent';

        $claimed = DB::transaction(function () use ($projectIds, $claimable, $agent) {
            $query = Task::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', $claimable)
                ->orderBy('position')
                ->orderBy('id');

            // Postgres: never hand the same row to two concurrent agents.
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            }

            $task = $query->first();
            if (! $task) {
                return null;
            }

            $working = Task::CLAIM_MAP[$task->status];

            // The WHERE-on-old-status is the real guard: the flip lands only if the
            // row is still queued, so even without SKIP LOCKED two claimers can't
            // both win (mirrors Review::claimFor). status_changed_at is set here
            // because a builder update bypasses the model's saving hook.
            $affected = Task::whereKey($task->id)
                ->where('status', $task->status)
                ->update([
                    'status' => $working,
                    'claimed_by' => $agent,
                    'claimed_at' => now(),
                    'status_changed_at' => now(),
                ]);

            return $affected === 1 ? $task->fresh() : null;
        });

        if (! $claimed) {
            return Response::json(['claimed' => false, 'message' => 'No task available to claim.']);
        }

        return Response::json([
            'claimed' => true,
            'task' => [
                'id' => $claimed->id,
                'project_id' => $claimed->project_id,
                'title' => $claimed->title,
                'status' => $claimed->status,
                'phase' => Task::phaseFor($claimed->status),
                'claimed_by' => $claimed->claimed_by,
            ],
            'next' => 'Call get_skill with this task_id to load the phase prompt, then advance_task when done.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Optional project id or slug to claim from. Omit to claim across all your projects.'),
            'phase' => $schema->string()->enum(array_values(Task::PHASE_FOR_WORKING))->description('Optional phase to restrict to: plan, develop, ai_review, merge.'),
            'agent_id' => $schema->string()->description('Identifier recorded as the holder. Defaults to your token name.'),
        ];
    }
}
