<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.index') }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }}</h2>
        </div>
    </x-slot>

    @php
        $labels = ['open' => 'Open', 'doing' => 'Doing', 'done' => 'Done'];
        $next   = ['open' => 'doing', 'doing' => 'done', 'done' => null];
        $prev   = ['open' => null, 'doing' => 'open', 'done' => 'doing'];
    @endphp

    <div class="py-10"
         x-data="{
            search: '',
            category: '',
            showArchived: false,
            cardMatches(el) {
                const title = (el.dataset.title || '').toLowerCase();
                const cat = (el.dataset.category || '');
                const q = this.search.trim().toLowerCase();
                const okText = !q || title.includes(q) || cat.toLowerCase().includes(q);
                const okCat = !this.category || cat === this.category;
                return okText && okCat;
            },
            cardHidden(el) { return !this.cardMatches(el); }
         }"
         x-init="
            $nextTick(() => window.initBoard(
                $refs.board,
                '{{ url('/tasks/__ID__/move') }}',
                '{{ csrf_token() }}'
            ));
         ">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($project->primary_goal)
                <p class="text-gray-600">{{ $project->primary_goal }}</p>
            @endif

            {{-- toolbar: search + category filter + archived toggle --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="relative flex-1">
                    <input x-model="search" type="search" placeholder="Search cards by title or category…"
                           class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                @if ($categories->isNotEmpty())
                    <select x-model="category"
                            class="sm:w-48 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" @click="showArchived = !showArchived"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                    <span x-text="showArchived ? 'Hide archived' : 'Show archived'"></span>
                    <span class="text-gray-400">({{ $archived->count() }})</span>
                </button>
            </div>

            {{-- board --}}
            <div x-ref="board" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach ($columns as $col)
                    <div class="bg-gray-100 rounded-lg p-3 flex flex-col"
                         x-data="{ get visibleCount() {
                            return Array.from($el.querySelectorAll('[data-task-id]'))
                                .filter((el) => cardMatches(el)).length;
                         } }">
                        <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide px-1 mb-3 flex items-center justify-between">
                            <span>
                                {{ $labels[$col] }}
                                <span class="text-gray-400" x-text="'(' + visibleCount + ')'"></span>
                            </span>
                        </h3>

                        {{-- droppable list --}}
                        <div data-column="{{ $col }}"
                             class="space-y-2 min-h-[3rem] flex-1">
                            @foreach ($byStatus->get($col, collect()) as $task)
                                <div data-task-id="{{ $task->id }}"
                                     data-title="{{ $task->title }}"
                                     data-category="{{ $task->category }}"
                                     x-show="cardMatches($el)"
                                     class="bg-white rounded-md shadow-sm p-3 cursor-grab active:cursor-grabbing">
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
                                            <button class="text-gray-300 hover:text-red-500 text-xs px-1" title="Archive">&times;</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- empty state (when no cards match the current filter) --}}
                        <p class="text-xs text-gray-400 italic px-1 py-2"
                           x-show="visibleCount === 0">
                            No cards here.
                        </p>

                        {{-- inline add card for this column --}}
                        <form method="POST" action="{{ route('tasks.store', $project) }}" class="mt-2"
                              x-data="{ open: false }">
                            @csrf
                            <input type="hidden" name="status" value="{{ $col }}">
                            <button type="button" x-show="!open" @click="open = true; $nextTick(() => $refs.title.focus())"
                                    class="w-full text-left text-xs text-gray-400 hover:text-gray-600 px-1 py-1.5">
                                + Add card
                            </button>
                            <div x-show="open" x-cloak class="space-y-2">
                                <input x-ref="title" name="title" placeholder="Card title"
                                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                                <input name="category" placeholder="Category (optional)"
                                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                                <div class="flex items-center gap-2">
                                    <x-primary-button class="!py-1.5 !text-xs">Add</x-primary-button>
                                    <button type="button" @click="open = false" class="text-xs text-gray-400 hover:text-gray-600">Cancel</button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('title')" />

            {{-- archived (cancelled) drawer --}}
            <div x-show="showArchived" x-cloak
                 class="bg-white shadow-sm sm:rounded-lg p-4 space-y-3">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">
                    Archived <span class="text-gray-400">({{ $archived->count() }})</span>
                </h3>
                @forelse ($archived as $task)
                    <div class="flex items-center gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        @if ($task->category)
                            <span class="inline-block text-[11px] font-medium uppercase tracking-wide text-gray-500 bg-gray-100 rounded px-1.5 py-0.5">{{ $task->category }}</span>
                        @endif
                        <span class="text-sm text-gray-500 line-through flex-1">{{ $task->title }}</span>
                        <form method="POST" action="{{ route('tasks.update', $task) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="open">
                            <button class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Restore</button>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic">Nothing archived.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
