<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="min-w-0">
                <x-breadcrumb :trail="[
                    ['label' => 'Projects', 'url' => route('projects.index')],
                    ['label' => $project->name],
                ]" />
                <h2 class="font-semibold text-xl text-gray-800 leading-tight truncate">{{ $project->name }}</h2>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <a href="{{ route('projects.gantt', $project) }}"
                   class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Timeline &rarr;</a>
                <a href="{{ route('work-sessions.index', $project) }}"
                   class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Sessions &rarr;</a>
                <a href="{{ route('repositories.index', $project) }}"
                   class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Repositories &rarr;</a>
                <a href="{{ route('projects.settings', $project) }}"
                   class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Settings &rarr;</a>
            </div>
        </div>
    </x-slot>

    @php
        $Task = \App\Models\Task::class;

        // Presentation maps for the actor categories (who a card waits on).
        $actorChip = [
            $Task::ACTOR_NEEDS_HUMAN => ['ring-amber-300', 'bg-amber-50', 'text-amber-700'],
            $Task::ACTOR_QUEUED      => ['ring-blue-200',  'bg-blue-50',  'text-blue-700'],
            $Task::ACTOR_AI_WORKING  => ['ring-violet-300','bg-violet-50','text-violet-700'],
            $Task::ACTOR_DONE        => ['ring-emerald-200','bg-emerald-50','text-emerald-700'],
        ];
        $accent = [
            $Task::ACTOR_NEEDS_HUMAN => 'border-l-amber-400',
            $Task::ACTOR_QUEUED      => 'border-l-blue-400',
            $Task::ACTOR_AI_WORKING  => 'border-l-violet-400',
            $Task::ACTOR_DONE        => 'border-l-emerald-400',
        ];
        $actorTag = [
            $Task::ACTOR_NEEDS_HUMAN => 'needs you',
            $Task::ACTOR_QUEUED      => 'queued for AI',
            $Task::ACTOR_AI_WORKING  => 'AI working',
            $Task::ACTOR_DONE        => 'done',
        ];
        $cardData = compact('actorChip', 'accent', 'actorTag');
    @endphp

    <div class="py-10"
         x-data="{
            search: '',
            category: '',
            priorities: [],
            showArchived: false,
            cardMatches(el) {
                const title = (el.dataset.title || '').toLowerCase();
                const cat = (el.dataset.category || '');
                const prio = (el.dataset.priority || '');
                const id = (el.dataset.taskId || '');
                const q = this.search.trim().toLowerCase();
                const qId = q.replace(/^#/, '');
                const okText = !q || title.includes(q) || cat.toLowerCase().includes(q) || (qId !== '' && id === qId);
                const okCat = !this.category || cat === this.category;
                const okPrio = this.priorities.length === 0 || this.priorities.includes(prio);
                return okText && okCat && okPrio;
            }
         }"
         x-init="
            $nextTick(() => window.initBoard(
                $refs.board,
                '{{ url('/tasks/__ID__/move') }}',
                '{{ csrf_token() }}'
            ));
         ">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($project->primary_goal)
                <p class="text-gray-600">{{ $project->primary_goal }}</p>
            @endif

            {{-- Connect an agent: kick off the autonomous work loop --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Run the work loop</p>
                    <p class="text-xs text-gray-500">
                        Paste this into a fresh Claude Code session (with the Lodestar MCP connected) to claim and
                        work every ready task on this project. It self-paces until the backlog is dry.
                    </p>
                    <span class="text-xs">
                        <a href="{{ route('secrets.index', $project) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Manage secrets &rarr;</a>
                        <span class="text-gray-300 mx-1">·</span>
                        <a href="{{ route('tools.index', $project) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Tools &rarr;</a>
                    </span>
                </div>
                @include('projects.partials.loop-prompt', ['project' => $project])
            </div>

            @if ($byStatus->isEmpty() && $archived->isEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-center space-y-2">
                    <h3 class="font-medium text-gray-900">No tasks yet</h3>
                    <p class="text-sm text-gray-500">
                        Use <span class="font-medium text-gray-700">+ Add card</span> in any column below to add your first task —
                        or let a connected agent queue one for you.
                    </p>
                </div>
            @endif

            {{-- toolbar: search + category filter + archived toggle + legend --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-col gap-3">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="relative flex-1">
                        <input x-model="search" type="search" placeholder="Search cards by title or category…"
                               class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    @if ($categories->isNotEmpty())
                        <x-select x-model="category"
                                class="sm:w-48 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">All categories</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </x-select>
                    @endif
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" @click.outside="open = false"
                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                            <span>Priority</span>
                            <span class="text-gray-400" x-show="priorities.length" x-text="'(' + priorities.length + ')'"></span>
                            <svg class="size-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </button>
                        <div x-show="open" x-cloak
                             class="absolute right-0 z-10 mt-1 w-40 rounded-md border border-gray-200 bg-white shadow-lg p-2 space-y-1">
                            @foreach (\App\Models\Task::PRIORITIES as $p)
                                <label class="flex items-center gap-2 text-sm text-gray-700 px-1 py-0.5 rounded hover:bg-gray-50 cursor-pointer">
                                    <input type="checkbox" value="{{ $p }}" x-model="priorities"
                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    <span class="capitalize">{{ $p }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <button type="button" @click="showArchived = !showArchived"
                            class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        <span x-text="showArchived ? 'Hide archived' : 'Show archived'"></span>
                        <span class="text-gray-400">({{ $archived->count() }})</span>
                    </button>
                </div>
                {{-- legend: what the colours mean --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-gray-500">
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-amber-400"></span> needs you</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-blue-400"></span> queued for AI</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-violet-400 animate-pulse"></span> AI working</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-emerald-400"></span> done</span>
                </div>
            </div>

            {{-- board: 5 phase columns. The 5-up layout engages at lg (1024px) so a
                 laptop or a 2K monitor split in half (~1270–1278px) comfortably shows
                 all five phase columns side by side. Below that: 2 columns (md), then 1.
                 Stock Tailwind breakpoints only — no custom breakpoint to mis-compile. --}}
            <div x-ref="board" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-start">
                @foreach ($phases as $phaseKey => $phase)
                    @php
                        $phaseStatuses = $phase['statuses'];
                        $workingStatuses = array_values(array_intersect($phaseStatuses, \App\Models\Task::workingStatuses()));
                        $restStatuses = array_values(array_diff($phaseStatuses, $workingStatuses));

                        $workingTasks = collect($workingStatuses)
                            ->flatMap(fn ($s) => $byStatus->get($s, collect()));
                        $restTasks = collect($restStatuses)
                            ->flatMap(fn ($s) => $byStatus->get($s, collect()));
                    @endphp

                    <div class="bg-gray-100 rounded-lg p-3 flex flex-col gap-2"
                         x-data="{ get visibleCount() {
                            return Array.from($el.querySelectorAll('[data-task-id]'))
                                .filter((el) => cardMatches(el)).length;
                         } }">
                        <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide px-1 flex items-center justify-between">
                            <span>{{ $phase['label'] }}</span>
                            <span class="text-gray-400 normal-case" x-text="'(' + visibleCount + ')'"></span>
                        </h3>

                        {{-- AI-working drawer (collapsed by default) --}}
                        @if ($workingTasks->isNotEmpty())
                            <div x-data="{ open: false }"
                                 class="rounded-md border border-violet-200 bg-violet-50/60">
                                <button type="button" @click="open = !open"
                                        class="w-full flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium text-violet-700">
                                    <span class="relative flex size-2">
                                        <span class="absolute inline-flex size-2 rounded-full bg-violet-400 opacity-75 animate-ping"></span>
                                        <span class="relative inline-flex size-2 rounded-full bg-violet-500"></span>
                                    </span>
                                    <span>AI working ({{ $workingTasks->count() }})</span>
                                    <svg class="size-3.5 ml-auto transition-transform" :class="open && 'rotate-90'"
                                         viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                                </button>
                                <div x-show="open" x-cloak class="px-1.5 pb-1.5 space-y-1.5">
                                    @foreach ($workingTasks as $task)
                                        @include('projects.partials.card', array_merge($cardData, ['task' => $task, 'compact' => true]))
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- droppable list of full cards (needs-human + queued + done) --}}
                        <div data-phase="{{ $phaseKey }}"
                             class="space-y-2 min-h-[3rem] flex-1">
                            @foreach ($restTasks as $task)
                                @include('projects.partials.card', array_merge($cardData, ['task' => $task, 'compact' => false]))
                            @endforeach
                        </div>

                        {{-- empty state (when no cards match the current filter) --}}
                        <p class="text-xs text-gray-400 italic px-1 py-2"
                           x-show="visibleCount === 0">
                            No cards here.
                        </p>

                        {{-- inline add card — lands in the phase's first status --}}
                        <form method="POST" action="{{ route('tasks.store', $project) }}"
                              x-data="{ open: false }">
                            @csrf
                            <input type="hidden" name="status" value="{{ $phaseStatuses[0] }}">
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
                                    <x-select name="priority"
                                            class="flex-1 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                        @foreach (\App\Models\Task::PRIORITIES as $p)
                                            <option value="{{ $p }}" @selected($p === \App\Models\Task::PRIORITY_NORMAL)>{{ ucfirst($p) }}</option>
                                        @endforeach
                                    </x-select>
                                    <input type="date" name="due_date" title="Due date (optional)"
                                           class="flex-1 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                                </div>
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
            <x-input-error :messages="$errors->get('status')" />

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
                        <a href="{{ route('tasks.show', $task) }}" class="text-sm text-gray-500 line-through flex-1 hover:text-gray-700">{{ $task->title }}</a>
                        <span class="text-[10px] uppercase tracking-wide text-gray-400">archived</span>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic">Nothing archived.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
