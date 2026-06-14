<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('work-sessions.index', $session->project) }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $session->title }}</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-gray-600">
                    @if ($session->occurred_on)
                        <span>{{ $session->occurred_on->toFormattedDateString() }}</span>
                    @endif
                    @if ($session->task)
                        <span>Task
                            <a href="{{ route('tasks.show', $session->task) }}"
                               class="text-indigo-600 hover:underline">{{ $session->task->title }}</a>
                        </span>
                    @endif
                    <a href="{{ route('projects.show', $session->project) }}"
                       class="text-indigo-600 hover:underline">{{ $session->project->name }}</a>
                </div>

                @if ($session->body)
                    <x-markdown :content="$session->body" />
                @else
                    <p class="text-sm text-gray-400 italic">No summary recorded.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
