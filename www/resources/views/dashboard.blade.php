<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    @php
        $T = \App\Models\Task::class;
        $statusLabel = fn ($s) => $T::LABELS[$s] ?? $s;
        // Pure flexbox, no fixed heights anywhere. The app shell makes <main> the
        // scrollable body below a fixed nav + header. Here the dashboard fills <main>
        // (lg:h-full) and its four rows are each lg:flex-1 — so they grow EQUALLY to
        // fill the height. Inside every card the header is fixed (shrink-0) and the
        // list grows (lg:flex-1) and scrolls (min-h-0). On mobile every lg: rule is off
        // → everything is plain content height and the page scrolls (no point filling
        // a stacked column). Asymmetric padding (pl-5 pr-2) + the list's pr-2 keep the
        // scrollbar at the right edge with a small gap to the content.
        $listClass = 'mt-3 pr-2 overflow-y-auto overflow-x-hidden lg:flex-1 lg:min-h-0';
        $barList = $listClass;
        $rowClass = 'flex items-center justify-between gap-3 border-b border-gray-100 py-2 last:border-0 hover:bg-gray-50 rounded transition';
        $badge = 'shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5';
        $head = 'flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-700 shrink-0';
        // Card shell. A pane sits in a 2-col grid row (the ROW carries lg:flex-1); a bar
        // is a direct flex child of the body, so it carries lg:flex-1 itself.
        $pane = 'bg-white shadow-sm sm:rounded-lg pl-5 pr-2 py-5 flex flex-col overflow-hidden lg:min-h-0';
        $bar = 'bg-white shadow-sm sm:rounded-lg pl-5 pr-2 py-5 flex flex-col overflow-hidden lg:flex-1 lg:min-h-0';
    @endphp

    {{-- lg+: fills <main> so the 2×2 grows within the available area and scrolls inside.
         Mobile: natural flow, the page scrolls. --}}
    <div class="flex flex-col gap-4 max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 lg:h-full lg:min-h-0">

        @include('partials.onboarding')

        {{-- Overdue / due soon — compact full-width bar, top --}}
        <section class="{{ $bar }}">
            <h3 class="{{ $head }}">
                <span class="size-2 rounded-full bg-red-400"></span>
                Overdue / due soon
                <span class="text-gray-400 normal-case">({{ $dueSoon->count() }})</span>
            </h3>
            <div class="{{ $barList }}">
                @forelse ($dueSoon as $task)
                    <a href="{{ route('tasks.show', $task) }}" class="{{ $rowClass }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                            <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                        </div>
                        <span class="shrink-0 text-xs font-medium {{ $task->isOverdue() ? 'text-red-600' : 'text-gray-500' }}">
                            {{ $task->due_date->toFormattedDateString() }}{{ $task->isOverdue() ? ' (overdue)' : '' }}
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-gray-400 italic">Nothing due in the next week.</p>
                @endforelse
            </div>
        </section>

        {{-- Inbox row 1 — its own flex sibling so it shares the root's gap evenly with
             the bars and row 2 (no nested 2×2 grid gap throwing the alignment off). --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:flex-1 lg:min-h-0">

            {{-- Backlog (new) --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="size-2 rounded-full bg-sky-400"></span>
                    Backlog
                    <span class="text-gray-400 normal-case">({{ $backlog->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
                    @forelse ($backlog as $task)
                        <a href="{{ route('tasks.show', $task) }}" class="{{ $rowClass }}">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                            </div>
                            <span class="{{ $badge }} bg-sky-50 text-sky-700">New</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic">Backlog is empty.</p>
                    @endforelse
                </div>
            </section>

            {{-- Plans to review (plan_review) --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="size-2 rounded-full bg-amber-400"></span>
                    Plans to review
                    <span class="text-gray-400 normal-case">({{ $plansToApprove->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
                    @forelse ($plansToApprove as $task)
                        <a href="{{ route('tasks.show', $task) }}" class="{{ $rowClass }}">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                            </div>
                            <span class="{{ $badge }} bg-amber-50 text-amber-700">Plan ready</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic">No plans waiting.</p>
                    @endforelse
                </div>
            </section>

        </div>

        {{-- Inbox row 2 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:flex-1 lg:min-h-0">

            {{-- Reviews waiting — open reviews only (the review is the unit, not its tasks) --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="size-2 rounded-full bg-indigo-400"></span>
                    Reviews
                    <span class="text-gray-400 normal-case">({{ $reviewsToDo->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
                    @forelse ($reviewsToDo as $review)
                        <a href="{{ route('reviews.show', $review) }}" class="{{ $rowClass }}">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $review->title }}</div>
                                <div class="text-xs text-gray-400 truncate">
                                    {{ $review->project->name }} · {{ $review->assignee?->name ?? 'unassigned' }}
                                    @if ($review->tasks_count) · {{ $review->tasks_count }} {{ \Illuminate\Support\Str::plural('task', $review->tasks_count) }}@endif
                                </div>
                            </div>
                            <span class="{{ $badge }} bg-indigo-50 text-indigo-700">{{ ucfirst(str_replace('_', ' ', $review->status)) }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic">No reviews waiting.</p>
                    @endforelse
                </div>
            </section>

            {{-- AI working now (the *-ing states) --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-2 rounded-full bg-violet-400 opacity-75 animate-ping"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-violet-500"></span>
                    </span>
                    AI working now
                    <span class="text-gray-400 normal-case">({{ $aiWorking->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
                    @forelse ($aiWorking as $task)
                        <a href="{{ route('tasks.show', $task) }}" class="{{ $rowClass }}">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                            </div>
                            <span class="{{ $badge }} bg-violet-50 text-violet-700">{{ $statusLabel($task->status) }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic">No agents are working right now.</p>
                    @endforelse
                </div>
            </section>

        </div>

        {{-- Recent work sessions — compact full-width bar, bottom --}}
        <section class="{{ $bar }}">
            <h3 class="{{ $head }}">
                <span class="size-2 rounded-full bg-emerald-400"></span>
                Recent work sessions
                <span class="text-gray-400 normal-case">({{ $sessions->count() }})</span>
            </h3>
            <div class="{{ $barList }}">
                @forelse ($sessions as $session)
                    <a href="{{ route('work-sessions.show', $session) }}" class="{{ $rowClass }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-800 truncate">{{ $session->title }}</div>
                            <div class="text-xs text-gray-400 truncate">
                                {{ $session->project->name }}@if ($session->task) · {{ $session->task->title }}@endif
                            </div>
                        </div>
                        <span class="shrink-0 text-xs text-gray-400">{{ optional($session->occurred_on)->toFormattedDateString() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-400 italic">No work sessions logged yet.</p>
                @endforelse
            </div>
        </section>

    </div>
</x-app-layout>
