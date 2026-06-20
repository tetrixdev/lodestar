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
        @php
            // Group the task subset by status, ordered by lifecycle phase (Task::STATUSES
            // is the canonical lifecycle order); sort only WITHIN each status by sub_id.
            $statusOrder = array_flip($T::STATUSES);
            $groups = $tasks->groupBy('status')
                ->sortBy(fn ($g, $status) => $statusOrder[$status] ?? PHP_INT_MAX);
        @endphp
        <ul class="space-y-0.5">
            @foreach ($groups as $status => $group)
                @foreach ($group->sortBy('sub_id') as $task)
                    @php $isMerged = $task->status === $T::STATUS_MERGED; @endphp
                    <li class="flex items-center gap-1.5 text-[11px] min-w-0 {{ $isMerged ? 'text-emerald-600' : 'text-gray-600' }}">
                        <span class="{{ $isMerged ? 'text-emerald-500' : 'text-gray-400' }}">{{ sprintf('T%02d', $task->sub_id) }}</span>
                        <a href="{{ route('tasks.show', $task) }}" class="truncate {{ $isMerged ? 'hover:text-emerald-700' : 'hover:text-indigo-600' }}">{{ $task->title }}</a>
                        <span class="ml-auto shrink-0 size-1.5 rounded-full
                            {{ $isMerged ? 'bg-emerald-400' : ($T::actorFor($task->status) === $T::ACTOR_AI_WORKING ? 'bg-violet-400' : ($T::actorFor($task->status) === $T::ACTOR_QUEUED ? 'bg-blue-400' : ($T::actorFor($task->status) === $T::ACTOR_DONE ? 'bg-emerald-400' : 'bg-amber-400'))) }}"
                            title="{{ $T::LABELS[$task->status] ?? $task->status }}"></span>
                    </li>
                @endforeach
            @endforeach
        </ul>
    @else
        <p class="text-[11px] text-gray-400 italic">No tasks yet</p>
    @endif

</div>
