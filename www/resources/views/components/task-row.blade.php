@props(['task'])

@php
    /** @var \App\Models\Task $task */
    $T = \App\Models\Task::class;
    // State-aware destination: a card in a review state opens its OPEN review; any
    // other state opens task-show. Computed once on the model (Task::rowTarget()).
    $target = $task->rowTarget();
    $isMerged = $task->status === $T::STATUS_MERGED;
    $actor = $T::actorFor($task->status);
    $dot = $isMerged
        ? 'bg-emerald-400'
        : ($actor === $T::ACTOR_AI_WORKING ? 'bg-violet-400'
            : ($actor === $T::ACTOR_QUEUED ? 'bg-blue-400'
                : ($actor === $T::ACTOR_DONE ? 'bg-emerald-400' : 'bg-amber-400')));
    $statusLabel = $T::LABELS[$task->status] ?? $task->status;
@endphp

{{--
    A clickable task row (task #100 B / rework): the WHOLE row is a single click
    target — one anchor wrapping the id-chip, the title AND the end status-dot, so
    the gaps between them are clickable too (not three separate hit areas). The row
    carries a hover state (light background tint + the title shifts colour). The row
    has no nested interactive controls, so a single wrapping anchor is safe. Tap
    target stays usable at ~360px. Destination is review-vs-task-show by state
    (Task::rowTarget()).
--}}
<a href="{{ $target }}"
   {{ $attributes->merge(['class' => 'group flex items-center gap-1.5 text-[11px] min-w-0 rounded-md px-1.5 py-1.5 -mx-1.5 transition-colors '.($isMerged ? 'text-emerald-600 hover:bg-emerald-50' : 'text-gray-600 hover:bg-gray-50')]) }}
   title="{{ $task->title }} — {{ $statusLabel }}">
    <span class="shrink-0 px-0.5 font-medium {{ $isMerged ? 'text-emerald-500 group-hover:text-emerald-700' : 'text-gray-400 group-hover:text-indigo-600' }}">{{ sprintf('T%02d', $task->sub_id) }}</span>
    <span class="min-w-0 truncate {{ $isMerged ? 'group-hover:text-emerald-700' : 'group-hover:text-indigo-600' }}">{{ $task->title }}</span>
    @if ($task->is_corrective)
        <span class="shrink-0 text-[9px] uppercase tracking-wide text-amber-600" title="Corrective fix — skips the per-task human review">fix</span>
    @endif
    <span class="ml-auto shrink-0 grid place-items-center size-4">
        <span class="size-1.5 rounded-full {{ $dot }}"></span>
    </span>
</a>
