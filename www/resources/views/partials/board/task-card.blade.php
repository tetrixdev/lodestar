@php
    /** @var \App\Models\Task $task */
    $T = \App\Models\Task::class;
    $statusLabel = $T::LABELS[$task->status] ?? $task->status;
    $project = $task->project;
    $actor = $T::actorFor($task->status);
    $dot = $actor === $T::ACTOR_AI_WORKING ? 'bg-violet-400' : ($actor === $T::ACTOR_QUEUED ? 'bg-blue-400' : ($actor === $T::ACTOR_DONE ? 'bg-emerald-400' : 'bg-amber-400'));
@endphp

<div class="rounded-lg border border-gray-200 bg-white shadow-sm p-3 space-y-2">
    <div class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 text-white"
              style="background-color: {{ $project->chipColor() }}" title="{{ $project->name }}">{{ $project->chipCode() }}</span>
        @if ($task->category)
            <span class="text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">{{ $task->category }}</span>
        @endif
        <span class="ml-auto inline-flex items-center gap-1 text-[10px] text-gray-400">
            <span class="size-1.5 rounded-full {{ $dot }}"></span>{{ $statusLabel }}
        </span>
    </div>

    <a href="{{ route('tasks.show', $task) }}" class="block text-sm font-medium text-gray-800 hover:text-indigo-700">
        {{ $task->title }}
    </a>

    <div class="flex items-center gap-2 pt-1 border-t border-gray-100">
        @include('projects.partials.transitions', ['task' => $task, 'targets' => $task->allowedTransitions(), 'compact' => true])
    </div>
</div>
