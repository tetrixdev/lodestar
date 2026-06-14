<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('projects.show', $project) }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Timeline</h2>
            </div>
            <a href="{{ route('projects.show', $project) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">&larr; Board</a>
        </div>
    </x-slot>

    @php
        $T = \App\Models\Task::class;
    @endphp

    <div class="py-10">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- legend --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-wrap items-center gap-x-5 gap-y-1 text-[11px] text-gray-500">
                <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded bg-indigo-400"></span> on track</span>
                <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded bg-red-400"></span> overdue</span>
                <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded bg-gray-300"></span> done</span>
                <span class="inline-flex items-center gap-1"><span class="inline-block w-px h-3 bg-rose-500"></span> today</span>
                <span class="ml-auto text-gray-400">{{ $rangeStart->toFormattedDateString() }} → {{ $rangeEnd->toFormattedDateString() }}</span>
            </div>

            @if (! $hasTasks)
                <div class="bg-white shadow-sm sm:rounded-lg p-10 text-center text-gray-400 italic">
                    No live tasks to chart yet.
                </div>
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                    {{-- min width keeps bars usable on small screens; the panel scrolls horizontally --}}
                    <div class="min-w-[720px] space-y-6">
                        @foreach ($phases as $phaseKey => $phase)
                            @php $rows = $byPhase[$phaseKey] ?? collect(); @endphp
                            @if ($rows->isNotEmpty())
                                <div class="space-y-2">
                                    <h3 class="font-semibold text-gray-700 text-xs uppercase tracking-wide">{{ $phase['label'] }}</h3>

                                    <div class="space-y-1.5">
                                        @foreach ($rows as $row)
                                            @php
                                                $task = $row['task'];
                                                $isDone = $task->status === $T::STATUS_DONE;
                                                $isOverdue = $task->isOverdue();
                                                $barColor = $isDone ? 'bg-gray-300' : ($isOverdue ? 'bg-red-400' : 'bg-indigo-400');
                                                $statusLabel = $T::LABELS[$task->status] ?? $task->status;
                                            @endphp
                                            <div class="flex items-center gap-3">
                                                {{-- task label --}}
                                                <div class="w-32 sm:w-48 shrink-0 truncate">
                                                    <a href="{{ route('tasks.show', $task) }}" class="text-sm text-gray-800 hover:underline">{{ $task->title }}</a>
                                                    <div class="text-[10px] text-gray-400">{{ $statusLabel }}</div>
                                                </div>
                                                {{-- track --}}
                                                <div class="relative flex-1 h-7 rounded bg-gray-50 border border-gray-100">
                                                    {{-- today marker --}}
                                                    <div class="absolute top-0 bottom-0 w-px bg-rose-500/70"
                                                         style="left: {{ $todayPct }}%"></div>
                                                    {{-- bar --}}
                                                    <div class="absolute top-1 bottom-1 rounded {{ $barColor }} flex items-center"
                                                         style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"
                                                         title="{{ $task->title }} — {{ $row['start']->toFormattedDateString() }} → {{ $row['end']->toFormattedDateString() }}">
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
