<x-app-layout>
    @php $T = \App\Models\Task::class; @endphp
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Agents') }}</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <p class="text-sm text-gray-600">
                    Agents currently working your projects — derived from cards in a working state. A
                    <span class="font-medium text-emerald-700">loop</span> agent claims with <code>agent_id:"loop"</code>.
                </p>
            </div>

            @forelse ($agents as $agent)
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                        </span>
                        <span class="font-semibold text-gray-800">{{ $agent['name'] }}</span>
                        @if ($agent['is_loop'])
                            <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-emerald-100 text-emerald-800">loop</span>
                        @endif
                        <span class="text-xs text-gray-400">{{ $agent['projects']->implode(', ') }}</span>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach ($agent['tasks'] as $task)
                            <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                                <a href="{{ route('tasks.show', $task) }}" class="text-indigo-600 hover:underline truncate">
                                    <span class="text-gray-400">#{{ $task->id }}</span> {{ $task->title }}
                                </a>
                                <div class="flex shrink-0 items-center gap-2 text-xs text-gray-500">
                                    <span class="rounded px-1.5 py-0.5 bg-gray-100 text-gray-600">{{ $T::LABELS[$task->status] ?? $task->status }}</span>
                                    <span>{{ $task->project->name }}</span>
                                    @if ($task->claimed_at)<span>· {{ $task->claimed_at->diffForHumans() }}</span>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-center text-sm text-gray-500">
                    No agents are working right now. Start one with a project's <strong>loop prompt</strong>.
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
