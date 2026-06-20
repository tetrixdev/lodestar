<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Claim the next available unit of WORK — a deliverable OR a task — and flip it to its working (*-ing) state. This is the loop\'s entry point. It hands out: claimable deliverables (planning / deliverable AI review / merge), standalone tasks, and child tasks — but a child task only once its deliverable is in `building` AND its dependencies are all done. Returns the claimed item, its `type`, and the `phase` to load a playbook for, or "no work available". Supersedes claim_task.')]
#[Name('claim_work')]
class ClaimWorkTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:task,deliverable'],
            'agent_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->currentUser($request);

        if (! empty($data['project'])) {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" is accessible to you.');
            }
            $projectIds = [$project->id];
        } else {
            $projectIds = Project::accessibleBy($user)->pluck('id')->all();
        }

        $agent = $data['agent_id'] ?? $user->currentAccessToken()?->name ?? 'agent';
        $type = $data['type'] ?? null;

        // Tasks first (drives parallel build inside a deliverable), then deliverables.
        if ($type !== 'deliverable') {
            $claimed = $this->claimTask($projectIds, $agent);
            if ($claimed) {
                return $this->claimedResponse('task', $claimed, Task::phaseFor($claimed->status), $claimed->rework_notes);
            }
        }

        if ($type !== 'task') {
            $claimed = $this->claimDeliverable($projectIds, $agent);
            if ($claimed) {
                return $this->claimedResponse('deliverable', $claimed, Deliverable::phaseFor($claimed->status));
            }
        }

        return Response::json(['claimed' => false, 'message' => 'No work available to claim.']);
    }

    /** Claim the next eligible task: claimable status, not blocked, and (if a child) its deliverable is building. */
    private function claimTask(array $projectIds, string $agent): ?Task
    {
        return DB::transaction(function () use ($projectIds, $agent) {
            $candidates = Task::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', Task::claimableStatuses())
                ->where(fn ($q) => $q
                    ->whereNull('deliverable_id')
                    ->orWhereHas('deliverable', fn ($d) => $d->where('status', Deliverable::STATUS_BUILDING)))
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($candidates as $task) {
                if ($task->isBlocked()) {
                    continue; // a dependency isn't done yet — not available
                }

                $working = Task::CLAIM_MAP[$task->status];
                $affected = Task::whereKey($task->id)
                    ->where('status', $task->status)
                    ->update(['status' => $working, 'claimed_by' => $agent, 'claimed_at' => now(), 'status_changed_at' => now()]);

                if ($affected === 1) {
                    return $task->fresh();
                }
            }

            return null;
        });
    }

    /** Claim the next claimable deliverable (planning / AI review / merge). */
    private function claimDeliverable(array $projectIds, string $agent): ?Deliverable
    {
        return DB::transaction(function () use ($projectIds, $agent) {
            $candidates = Deliverable::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', Deliverable::claimableStatuses())
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($candidates as $deliverable) {
                $working = Deliverable::CLAIM_MAP[$deliverable->status];
                $affected = Deliverable::whereKey($deliverable->id)
                    ->where('status', $deliverable->status)
                    ->update(['status' => $working, 'claimed_by' => $agent, 'claimed_at' => now(), 'status_changed_at' => now()]);

                if ($affected === 1) {
                    return $deliverable->fresh();
                }
            }

            return null;
        });
    }

    private function claimedResponse(string $type, Task|Deliverable $item, ?string $phase, ?string $reworkNotes = null): Response
    {
        $playbookArg = $type === 'task' ? "task_id={$item->id}" : "deliverable_id={$item->id}";

        return Response::json([
            'claimed' => true,
            'type' => $type,
            'work' => [
                'id' => $item->id,
                'type' => $type,
                'project_id' => $item->project_id,
                'title' => $item->title,
                'status' => $item->status,
                'phase' => $phase,
                'claimed_by' => $item->claimed_by,
                'rework_notes' => $reworkNotes,
            ],
            'next' => "Call get_playbook with {$playbookArg} to load the {$phase} prompt, then advance_"
                .($type === 'task' ? 'task' : 'deliverable').' when done.'
                .($reworkNotes ? ' This item has rework_notes from a review — address them first.' : ''),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Optional project id or slug to claim from. Omit to claim across all your projects.'),
            'type' => $schema->string()->enum(['task', 'deliverable'])->description('Optionally restrict to claiming only a task or only a deliverable. Omit to take whichever is next (tasks preferred).'),
            'agent_id' => $schema->string()->description('Identifier recorded as the holder. Defaults to your token name.'),
        ];
    }
}
