<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    @php
        $T = \App\Models\Task::class;
        $statusLabel = fn ($s) => $T::LABELS[$s] ?? $s;
        // No forced heights — each pane sizes to its content (a long list is tall, a
        // short one is short). No min padding, no max cap, no internal scroll: if the
        // whole board outgrows the screen, the page scrolls. Forcing a height (min/
        // max/calc/flex-fill) is what created the "too tall / empty whitespace" boxes.
        $listClass = 'mt-3';
        $rowClass = 'flex items-center justify-between gap-3 border-b border-gray-100 py-2 last:border-0 hover:bg-gray-50 rounded transition';
        $badge = 'shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5';
        $head = 'flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-700';
        $pane = 'bg-white shadow-sm sm:rounded-lg p-5';
    @endphp

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @include('partials.onboarding')

            {{-- Overdue / due soon — full width, top --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="size-2 rounded-full bg-red-400"></span>
                    Overdue / due soon
                    <span class="text-gray-400 normal-case">({{ $dueSoon->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
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

            {{-- Inbox — 2×2; every pane uses the same sizing rule --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

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

            {{-- Recent work sessions — full width, bottom --}}
            <section class="{{ $pane }}">
                <h3 class="{{ $head }}">
                    <span class="size-2 rounded-full bg-emerald-400"></span>
                    Recent work sessions
                    <span class="text-gray-400 normal-case">({{ $sessions->count() }})</span>
                </h3>
                <div class="{{ $listClass }}">
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
    </div>
</x-app-layout>
