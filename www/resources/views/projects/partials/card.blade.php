@php
    /** @var \App\Models\Task $task */
    $T = \App\Models\Task::class;
    $compact = $compact ?? false;

    $actor = $T::actorFor($task->status);
    [$ring, $chipBg, $chipText] = $actorChip[$actor] ?? $actorChip[$T::ACTOR_NEEDS_HUMAN];
    $accentBar = $accent[$actor] ?? 'border-l-gray-300';
    $tag = $actorTag[$actor] ?? '';
    $statusLabel = $T::LABELS[$task->status] ?? $task->status;
    $isWorking = $actor === $T::ACTOR_AI_WORKING;
    $isQueued = $actor === $T::ACTOR_QUEUED;

    // "Nh in <status>" timer from status_changed_at (humanised, single part).
    $since = $task->status_changed_at
        ? $task->status_changed_at->diffForHumans(['short' => true, 'parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])
        : null;

    $targets = $task->allowedTransitions();
    // The first review covering this card (if any) — a small "Review" link.
    $reviewLink = $task->relationLoaded('reviews') ? $task->reviews->first() : $task->reviews()->first();

    $priority = $task->priority ?? $T::PRIORITY_NORMAL;
    $priorityChip = [
        $T::PRIORITY_LOW    => 'bg-gray-100 text-gray-600',
        $T::PRIORITY_NORMAL => 'bg-slate-100 text-slate-700',
        $T::PRIORITY_HIGH   => 'bg-amber-100 text-amber-800',
        $T::PRIORITY_URGENT => 'bg-red-100 text-red-700',
    ];
    $overdue = $task->isOverdue();
    $blocked = $task->isBlocked();
@endphp

<div data-task-id="{{ $task->id }}"
     data-status="{{ $task->status }}"
     data-title="{{ $task->title }}"
     data-category="{{ $task->category }}"
     data-priority="{{ $priority }}"
     x-show="cardMatches($el)"
     class="group bg-white rounded-md shadow-sm border-l-4 {{ $accentBar }} {{ $compact ? 'px-2.5 py-1.5' : 'p-3' }}">

    @if ($compact)
        {{-- compact one-liner for the AI-working drawer --}}
        <div class="flex items-center gap-2">
            <span class="relative flex size-1.5 shrink-0">
                <span class="absolute inline-flex size-1.5 rounded-full bg-violet-400 opacity-75 animate-ping"></span>
                <span class="relative inline-flex size-1.5 rounded-full bg-violet-500"></span>
            </span>
            <span class="text-xs text-gray-800 truncate flex-1"><span class="text-gray-400">#{{ $task->id }}</span> {{ $task->title }}</span>
            @if ($since)
                <span class="text-[10px] text-gray-400 shrink-0" title="in {{ $statusLabel }}">{{ $since }}</span>
            @endif
        </div>
        <div class="mt-1 flex items-center gap-1">
            @include('projects.partials.transitions', ['task' => $task, 'targets' => $targets, 'compact' => true])
            @if ($isWorking)
                @include('projects.partials.release', ['task' => $task])
            @endif
        </div>
        @if ($isWorking && $task->claimed_by)
            <div class="mt-1 text-[10px] text-gray-400 truncate" title="claimed by">held by {{ $task->claimed_by }}</div>
        @endif
        <div class="mt-1">
            @include('projects.partials.copy-prompt', ['task' => $task])
        </div>
    @else
        {{-- full card --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 ring-1 {{ $ring }} {{ $chipBg }} {{ $chipText }}">
                @if ($isWorking)
                    <span class="size-1.5 rounded-full bg-current animate-pulse"></span>
                @elseif ($isQueued)
                    <svg class="size-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/></svg>
                @endif
                {{ $tag }}
            </span>
            @if ($task->category)
                <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">{{ $task->category }}</span>
            @endif
            <span class="inline-block text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 {{ $priorityChip[$priority] ?? $priorityChip[$T::PRIORITY_NORMAL] }}">{{ $priority }}</span>
            @if ($blocked)
                <span class="inline-block text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-red-100 text-red-700">blocked</span>
            @endif
        </div>

        <div class="text-sm text-gray-900 mt-1.5">
            <a href="{{ route('tasks.show', $task) }}" class="hover:underline"><span class="text-gray-400 font-medium">#{{ $task->id }}</span> {{ $task->title }}</a>
        </div>

        @if (filled($task->body_summary))
            {{-- The scannable TL;DR, rendered as markdown (bold/lists/links read),
                 clamped to two lines so the card stays compact; the full summary
                 still reads on the task-show page. --}}
            <div class="mt-1 text-xs text-gray-500 line-clamp-2 [&_*]:inline [&_p]:inline [&_strong]:font-semibold">
                <x-markdown :content="$task->body_summary" />
            </div>
        @endif

        @if ($reviewLink)
            <a href="{{ route('reviews.show', $reviewLink) }}"
               class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-indigo-600 hover:underline">
                <svg class="size-3" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/><path fill-rule="evenodd" d="M.664 10.59a1.65 1.65 0 0 1 0-1.18 10.003 10.003 0 0 1 18.672 0 1.65 1.65 0 0 1 0 1.18 10.003 10.003 0 0 1-18.672 0ZM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" clip-rule="evenodd"/></svg>
                Review
            </a>
        @endif

        <div class="mt-2 flex items-center justify-between gap-2">
            <span class="text-[11px] font-medium text-gray-600">{{ $statusLabel }}</span>
            @if ($since)
                <span class="text-[11px] text-gray-400" title="time in this status">{{ $since }} in status</span>
            @endif
        </div>

        @if ($task->due_date)
            <div class="mt-1 text-[11px] {{ $overdue ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                Due {{ $task->due_date->toFormattedDateString() }}{{ $overdue ? ' · overdue' : '' }}
            </div>
        @endif

        <div class="mt-2 flex items-center gap-1">
            @include('projects.partials.transitions', ['task' => $task, 'targets' => $targets, 'compact' => false])
            @if ($isWorking)
                @include('projects.partials.release', ['task' => $task])
            @endif
        </div>
        @if ($isQueued || $isWorking)
            <div class="mt-1">
                @include('projects.partials.copy-prompt', ['task' => $task])
            </div>
        @endif
        @if ($isWorking && $task->claimed_by)
            <div class="mt-1 text-[11px] text-gray-400">held by {{ $task->claimed_by }}</div>
        @endif
    @endif
</div>
