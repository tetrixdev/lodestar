<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    @php
        $T = \App\Models\Task::class;
        $statusLabel = fn ($s) => $T::LABELS[$s] ?? $s;
    @endphp

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @include('partials.onboarding')

            @php $inboxCount = $reviewsToDo->count() + $awaitingReview->count() + $plansToApprove->count() + $toTriage->count(); @endphp

            {{-- ── Your inbox: what needs you, by the action it needs ───────────── --}}
            <section class="space-y-4">
                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-700">
                    Your inbox
                    <span class="text-gray-400 normal-case">({{ $inboxCount }})</span>
                </h3>

                @if ($inboxCount === 0)
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 text-center text-sm text-gray-400 italic">
                        Inbox zero — nothing is waiting on you.
                    </div>
                @endif

                {{-- Review changes — open reviews, with their task(s) nested --}}
                @if ($reviewsToDo->isNotEmpty() || $awaitingReview->isNotEmpty())
                    <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                        <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-700">
                            <span class="size-2 rounded-full bg-indigo-400"></span>
                            Review changes
                            <span class="text-gray-400 normal-case">({{ $reviewsToDo->count() + $awaitingReview->count() }})</span>
                        </h4>
                        @foreach ($awaitingReview as $task)
                            <a href="{{ route('tasks.show', $task) }}"
                               class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                    <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                                </div>
                                <span class="shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-50 text-indigo-700">Awaiting review</span>
                            </a>
                        @endforeach
                        @foreach ($reviewsToDo as $review)
                            <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0 space-y-1">
                                <div class="flex items-start justify-between gap-3">
                                    <a href="{{ route('reviews.show', $review) }}" class="min-w-0 hover:underline">
                                        <div class="text-sm font-medium text-gray-800 truncate">{{ $review->title }}</div>
                                        <div class="text-xs text-gray-400 truncate">
                                            {{ $review->project->name }} · {{ $review->assignee?->name ?? 'unassigned' }}
                                        </div>
                                    </a>
                                    <span class="shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-50 text-indigo-700">{{ ucfirst(str_replace('_', ' ', $review->status)) }}</span>
                                </div>
                                @if ($review->tasks->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5 pl-0.5">
                                        @foreach ($review->tasks as $rtask)
                                            <a href="{{ route('tasks.show', $rtask) }}"
                                               class="text-[11px] rounded bg-gray-100 text-gray-600 px-1.5 py-0.5 hover:bg-gray-200 truncate max-w-[16rem]">#{{ $rtask->id }} {{ $rtask->title }}</a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Approve plans + Triage, side by side on wider screens --}}
                @if ($plansToApprove->isNotEmpty() || $toTriage->isNotEmpty())
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                        @if ($plansToApprove->isNotEmpty())
                            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-700">
                                    <span class="size-2 rounded-full bg-amber-400"></span>
                                    Approve plans
                                    <span class="text-gray-400 normal-case">({{ $plansToApprove->count() }})</span>
                                </h4>
                                @foreach ($plansToApprove as $task)
                                    <a href="{{ route('tasks.show', $task) }}"
                                       class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                            <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                                        </div>
                                        <span class="shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-50 text-amber-700">Plan ready</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if ($toTriage->isNotEmpty())
                            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                                <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-700">
                                    <span class="size-2 rounded-full bg-sky-400"></span>
                                    Triage
                                    <span class="text-gray-400 normal-case">({{ $toTriage->count() }})</span>
                                </h4>
                                @foreach ($toTriage as $task)
                                    <a href="{{ route('tasks.show', $task) }}"
                                       class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                            <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                                        </div>
                                        <span class="shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-sky-50 text-sky-700">New</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- ── Context: what the agents are up to ───────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

                {{-- AI working now --}}
                <section class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-700">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-2 rounded-full bg-violet-400 opacity-75 animate-ping"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-violet-500"></span>
                        </span>
                        AI working now
                        <span class="text-gray-400 normal-case">({{ $aiWorking->count() }})</span>
                    </h3>
                    @forelse ($aiWorking as $task)
                        <a href="{{ route('tasks.show', $task) }}"
                           class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate">{{ $task->title }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $task->project->name }}</div>
                            </div>
                            <span class="shrink-0 text-[11px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-violet-50 text-violet-700">{{ $statusLabel($task->status) }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic">No agents are working right now.</p>
                    @endforelse
                </section>

                {{-- Overdue / due soon --}}
                <section class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-700">
                        <span class="size-2 rounded-full bg-red-400"></span>
                        Overdue / due soon
                        <span class="text-gray-400 normal-case">({{ $dueSoon->count() }})</span>
                    </h3>
                    @forelse ($dueSoon as $task)
                        <a href="{{ route('tasks.show', $task) }}"
                           class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
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
                </section>

            </div>

            {{-- Recent work sessions --}}
            <section class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-700">
                    <span class="size-2 rounded-full bg-emerald-400"></span>
                    Recent work sessions
                    <span class="text-gray-400 normal-case">({{ $sessions->count() }})</span>
                </h3>
                @forelse ($sessions as $session)
                    <a href="{{ route('work-sessions.show', $session) }}"
                       class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 last:pb-0 hover:bg-gray-50 -mx-2 px-2 rounded transition">
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
            </section>

        </div>
    </div>
</x-app-layout>
