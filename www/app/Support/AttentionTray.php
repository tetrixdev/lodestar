<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlaybookVersion;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The nav "attention tray": a derived to-do count of things genuinely waiting on
 * THIS human and nothing else. Not a notification feed — it never clears on
 * view; an item drops off only when the human acts on it.
 *
 * Three buckets, by design (see the product decision behind this):
 *  - playbook proposals you can approve — otherwise invisible until you open Playbooks;
 *  - reviews you're holding — a claimed review is a commitment, finish or release;
 *  - overdue / due-soon tasks — a deadline nudge (the only "urgent"/red bucket).
 *
 * Deliberately NOT human-gate cards (plan_review / human_review): the board
 * always has some, so a persistent badge for them becomes wallpaper. The board
 * already surfaces those.
 */
class AttentionTray
{
    /** Review states that still want a reviewer's attention. */
    private const OPEN_REVIEW_STATES = ['draft', 'in_review'];

    /** Per-bucket cap on the items listed in the dropdown (the count is always the true total). */
    private const ITEM_LIMIT = 6;

    /**
     * @return array{total:int, urgent:int, buckets:array<int,array<string,mixed>>}
     */
    public static function for(User $user): array
    {
        $projectIds = Project::accessibleBy($user)->pluck('id');

        $playbooks = self::playbookProposals($user);
        $reviews = self::heldReviews($user, $projectIds);
        $overdue = self::dueSoonTasks($projectIds);

        $buckets = [
            self::bucket('playbooks', 'Playbook proposals to approve', 'amber', route('playbooks.index'), $playbooks),
            self::bucket('reviews', "Reviews you're holding", 'indigo', route('board'), $reviews),
            self::bucket('overdue', 'Overdue / due soon', 'red', route('board'), $overdue),
        ];

        return [
            'total' => array_sum(array_column($buckets, 'count')),
            'urgent' => $overdue->count(), // red — drives the badge colour
            'buckets' => $buckets,
        ];
    }

    /** Proposed playbook versions on any scope this user may approve. */
    private static function playbookProposals(User $user): Collection
    {
        return PlaybookVersion::query()
            ->where('status', PlaybookVersion::STATUS_PROPOSED)
            ->with(['playbook.owner'])
            ->latest()
            ->get()
            ->filter(fn (PlaybookVersion $v) => $v->playbook && $v->playbook->canBeApprovedBy($user))
            ->map(fn (PlaybookVersion $v) => [
                'label' => $v->playbook->scope.' · '.$v->playbook->key,
                'sub' => trim(($v->playbook->owner->name ?? 'system').($v->proposed_by_ai ? ' · by AI' : '')),
                'url' => route('playbooks.show', $v->playbook),
            ])
            ->values();
    }

    /** Open reviews this user has personally claimed — finish them or hand them back. */
    private static function heldReviews(User $user, Collection $projectIds): Collection
    {
        return Review::query()
            ->whereIn('project_id', $projectIds)
            ->where('assigned_to_user_id', $user->id)
            ->whereIn('status', self::OPEN_REVIEW_STATES)
            ->with('project:id,name')
            ->latest()
            ->get()
            ->map(fn (Review $r) => [
                'label' => $r->title,
                'sub' => $r->project->name ?? '',
                'url' => route('reviews.show', $r),
            ]);
    }

    /** Live tasks due in the next week (or already overdue). */
    private static function dueSoonTasks(Collection $projectIds): Collection
    {
        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
            ->whereNotIn('status', [Task::STATUS_MERGED, Task::STATUS_CANCELLED])
            ->with('project:id,name')
            ->orderBy('due_date')
            ->get()
            ->map(fn (Task $t) => [
                'label' => $t->title,
                'sub' => ($t->project->name ?? '').' · '.($t->isOverdue() ? 'overdue' : 'due '.$t->due_date->toFormattedDateString()),
                'url' => route('tasks.show', $t),
            ]);
    }

    /** Shape one bucket: total count, a capped item list, and its accent colour. */
    private static function bucket(string $key, string $label, string $color, string $allUrl, Collection $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'color' => $color,
            'all_url' => $allUrl,
            'count' => $items->count(),
            'items' => $items->take(self::ITEM_LIMIT)->all(),
        ];
    }
}
