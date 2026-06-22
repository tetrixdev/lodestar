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
    A clickable task row (task #100 B): the id-chip, the title AND the end status-dot
    each link to $target — three separate anchors so each is independently tappable
    (the row is not one giant anchor, to keep any nested controls usable). Tap
    targets are sized to stay usable at ~360px. Destination is review-vs-task-show by
    state (Task::rowTarget()).
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-1.5 text-[11px] min-w-0 '.($isMerged ? 'text-emerald-600' : 'text-gray-600')]) }}>
    <a href="{{ $target }}"
       class="shrink-0 py-1 -my-1 px-0.5 font-medium {{ $isMerged ? 'text-emerald-500 hover:text-emerald-700' : 'text-gray-400 hover:text-indigo-600' }}"
       title="{{ $task->title }}">{{ sprintf('T%02d', $task->sub_id) }}</a>
    <a href="{{ $target }}"
       class="min-w-0 truncate py-1 -my-1 {{ $isMerged ? 'hover:text-emerald-700' : 'hover:text-indigo-600' }}">{{ $task->title }}</a>
    @if ($task->is_corrective)
        <span class="shrink-0 text-[9px] uppercase tracking-wide text-amber-600" title="Corrective fix — skips the per-task human review">fix</span>
    @endif
    <a href="{{ $target }}"
       class="ml-auto shrink-0 grid place-items-center size-6 -m-1.5"
       title="{{ $statusLabel }}">
        <span class="size-1.5 rounded-full {{ $dot }}"></span>
    </a>
</div>
