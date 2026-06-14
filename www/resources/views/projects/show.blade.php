<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.index') }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }}</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($project->primary_goal)
                <p class="text-gray-600">{{ $project->primary_goal }}</p>
            @endif

            {{-- add card --}}
            <form method="POST" action="{{ route('tasks.store', $project) }}"
                  class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-col sm:flex-row gap-3">
                @csrf
                <input name="category" placeholder="Category (optional)"
                       class="sm:w-48 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                <input name="title" required placeholder="New task title"
                       class="flex-1 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                <x-primary-button>Add</x-primary-button>
            </form>
            <x-input-error :messages="$errors->get('title')" class="-mt-2" />

            {{-- board --}}
            @php
                $labels = ['open' => 'Open', 'doing' => 'Doing', 'done' => 'Done'];
                $next   = ['open' => 'doing', 'doing' => 'done', 'done' => null];
                $prev   = ['open' => null, 'doing' => 'open', 'done' => 'doing'];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach ($columns as $col)
                    <div class="bg-gray-100 rounded-lg p-3">
                        <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide px-1 mb-3">
                            {{ $labels[$col] }}
                            <span class="text-gray-400">({{ optional($byStatus->get($col))->count() ?? 0 }})</span>
                        </h3>
                        <div class="space-y-2 min-h-[2rem]">
                            @foreach ($byStatus->get($col, collect()) as $task)
                                <div class="bg-white rounded-md shadow-sm p-3">
                                    @if ($task->category)
                                        <span class="inline-block text-[11px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5 mb-1">{{ $task->category }}</span>
                                    @endif
                                    <div class="text-sm text-gray-900">{{ $task->title }}</div>
                                    <div class="flex items-center gap-1 mt-2">
                                        @if ($prev[$col])
                                            <form method="POST" action="{{ route('tasks.update', $task) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $prev[$col] }}">
                                                <button class="text-gray-400 hover:text-gray-700 text-xs px-1" title="Move to {{ $labels[$prev[$col]] }}">&larr;</button>
                                            </form>
                                        @endif
                                        @if ($next[$col])
                                            <form method="POST" action="{{ route('tasks.update', $task) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $next[$col] }}">
                                                <button class="text-gray-400 hover:text-gray-700 text-xs px-1" title="Move to {{ $labels[$next[$col]] }}">&rarr;</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('tasks.update', $task) }}" class="ml-auto">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="cancelled">
                                            <button class="text-gray-300 hover:text-red-500 text-xs px-1" title="Cancel">&times;</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
