@php
    /** @var \App\Models\Deliverable $deliverable */
    /** @var \Illuminate\Support\Collection $tasks the task subset to show in THIS column (board projection) */
    $D = \App\Models\Deliverable::class;
    $T = \App\Models\Task::class;
    $statusLabel = $D::LABELS[$deliverable->status] ?? $deliverable->status;
    $project = $deliverable->project;
    $tasks = $tasks ?? $deliverable->tasks;
@endphp

<div class="rounded-lg border border-indigo-200 bg-indigo-50/40 shadow-sm p-3 space-y-2">
    <div class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 text-white"
              style="background-color: {{ $project->chipColor() }}" title="{{ $project->name }}">{{ $project->chipCode() }}</span>
        <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-100 text-indigo-700">Deliverable</span>
        <span class="ml-auto text-[10px] text-gray-400">{{ $statusLabel }}</span>
    </div>

    <a href="{{ route('deliverables.show', $deliverable) }}" class="block text-sm font-medium text-gray-800 hover:text-indigo-700">
        {{ $deliverable->title }}
    </a>

    @if ($tasks->isNotEmpty())
        <ul class="space-y-0.5">
            @foreach ($tasks as $task)
                <li class="flex items-center gap-1.5 text-[11px] text-gray-600 min-w-0">
                    <span class="text-gray-400">{{ sprintf('T%02d', $task->sub_id) }}</span>
                    <a href="{{ route('tasks.show', $task) }}" class="truncate hover:text-indigo-600">{{ $task->title }}</a>
                    <span class="ml-auto shrink-0 size-1.5 rounded-full
                        {{ $T::actorFor($task->status) === $T::ACTOR_AI_WORKING ? 'bg-violet-400' : ($T::actorFor($task->status) === $T::ACTOR_QUEUED ? 'bg-blue-400' : ($T::actorFor($task->status) === $T::ACTOR_DONE ? 'bg-emerald-400' : 'bg-amber-400')) }}"
                        title="{{ $T::LABELS[$task->status] ?? $task->status }}"></span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-[11px] text-gray-400 italic">No tasks yet</p>
    @endif

    <div class="flex items-center gap-2 pt-1 border-t border-indigo-100">
        @include('deliverables.partials.transitions', ['deliverable' => $deliverable, 'targets' => $deliverable->allowedTransitions()])
    </div>
</div>
