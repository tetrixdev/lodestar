<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Liveness backstop for the agent loop. Entering a working (*-ing) state is
 * engine-enforced and atomic (claim_task); LEAVING it is prompt-driven — the
 * agent is told to advance_task when it finishes. A worker that crashes or
 * forgets would otherwise pin a card in developing / ai_review / merge_deploy /
 * planning forever. This reaper re-queues any working card whose
 * status_changed_at is older than the lease, so a dropped task flows back to
 * its ready_* queue for another run. The success path stays agent-driven (only
 * the agent knows it actually passed); this only catches the genuinely stalled.
 */
class ReapStalledTasks extends Command
{
    protected $signature = 'lodestar:reap-stalled-tasks {--minutes= : Override the lease window in minutes}';

    protected $description = 'Re-queue tasks stuck in a working state past the lease (a dead or forgetful worker).';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('lodestar.task_lease_minutes', 60));
        $cutoff = now()->subMinutes($minutes);

        $stalled = Task::query()
            ->whereIn('status', Task::workingStatuses())
            ->where('status_changed_at', '<', $cutoff)
            ->get();

        $reaped = 0;
        foreach ($stalled as $task) {
            $queue = Task::queueStateFor($task->status);
            if ($queue === null) {
                continue; // not a claimable working state — leave it untouched
            }

            $from = $task->status;
            // System recovery: re-queue directly (the model's saving hook re-stamps
            // status_changed_at; the claim is released). Logged so a revert is
            // never silent — it shows on the card's activity timeline.
            $task->update(['status' => $queue, 'claimed_by' => null, 'claimed_at' => null]);
            $task->logEvent('worker_reaped', 'system',
                "Stalled in {$from} for over {$minutes}m with no progress — re-queued to {$queue}.");

            $reaped++;
            $this->warn("#{$task->id} {$from} → {$queue}");
        }

        $this->info("Reaped {$reaped} stalled task".($reaped === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
