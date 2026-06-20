<x-app-layout>
    @php
        $T = \App\Models\Task::class;
        $statusLabel = $T::LABELS[$task->status] ?? $task->status;
        $blocked = $task->isBlocked();
        $overdue = $task->isOverdue();

        $priorityChip = [
            $T::PRIORITY_LOW    => 'bg-gray-100 text-gray-600',
            $T::PRIORITY_NORMAL => 'bg-blue-50 text-blue-700',
            $T::PRIORITY_HIGH   => 'bg-amber-100 text-amber-800',
            $T::PRIORITY_URGENT => 'bg-red-100 text-red-700',
        ];
        $priority = $task->priority ?? $T::PRIORITY_NORMAL;
    @endphp

    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Projects', 'url' => route('projects.index')],
                ['label' => $task->project->name, 'url' => route('projects.show', $task->project)],
                ['label' => '#'.$task->id.' '.$task->title],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight"><span class="text-gray-400">#{{ $task->id }}</span> {{ $task->title }}</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- header / meta --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3" x-data="{ editing: @js($errors->hasAny(['title', 'priority', 'category', 'branch', 'body', 'body_summary', 'plan', 'plan_summary', 'start_date', 'due_date'])) }">
                <div class="flex items-start justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-block text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-700">{{ $statusLabel }}</span>
                    <span class="inline-block text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 {{ $priorityChip[$priority] ?? $priorityChip[$T::PRIORITY_NORMAL] }}">{{ ucfirst($priority) }}</span>
                    @if ($task->category)
                        <span class="inline-block text-[11px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-2 py-0.5">{{ $task->category }}</span>
                    @endif
                    @if ($blocked)
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-red-100 text-red-700">Blocked</span>
                    @endif
                </div>
                    <button type="button" @click="editing = !editing"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        <span x-text="editing ? 'Cancel' : 'Edit task'"></span>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-gray-600" x-show="!editing">
                    @if ($task->due_date)
                        <span class="{{ $overdue ? 'text-red-600 font-medium' : '' }}">
                            Due {{ $task->due_date->toFormattedDateString() }}{{ $overdue ? ' (overdue)' : '' }}
                        </span>
                    @endif
                    @if ($task->start_date)
                        <span>Start {{ $task->start_date->toFormattedDateString() }}</span>
                    @endif
                    @if ($task->branch)
                        <span class="min-w-0 break-all">Branch <code class="bg-gray-100 px-1 rounded">{{ $task->branch }}</code></span>
                    @endif
                </div>

                {{-- edit panel: freely-editable content fields (status moves stay in Lifecycle) --}}
                <form x-show="editing" x-cloak method="POST" action="{{ route('tasks.update', $task) }}" class="space-y-4 pt-1">
                    @csrf @method('PATCH')

                    <div>
                        <x-input-label for="edit-title" value="Title" />
                        <x-text-input id="edit-title" name="title" type="text" class="mt-1 block w-full"
                                      :value="old('title', $task->title)" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="edit-priority" value="Priority" />
                            <x-select id="edit-priority" name="priority"
                                    class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($T::PRIORITIES as $p)
                                    <option value="{{ $p }}" @selected(old('priority', $priority) === $p)>{{ ucfirst($p) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-input-label for="edit-category" value="Category" />
                            <x-text-input id="edit-category" name="category" type="text" class="mt-1 block w-full"
                                          :value="old('category', $task->category)" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="edit-start" value="Start date" />
                            <x-text-input id="edit-start" name="start_date" type="date" class="mt-1 block w-full"
                                          :value="old('start_date', optional($task->start_date)->format('Y-m-d'))" />
                        </div>
                        <div>
                            <x-input-label for="edit-due" value="Due date" />
                            <x-text-input id="edit-due" name="due_date" type="date" class="mt-1 block w-full"
                                          :value="old('due_date', optional($task->due_date)->format('Y-m-d'))" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="edit-branch" value="Branch" />
                        <x-text-input id="edit-branch" name="branch" type="text" class="mt-1 block w-full"
                                      :value="old('branch', $task->branch)" />
                    </div>

                    <div>
                        <x-input-label for="edit-body-summary" value="Description summary" />
                        <p class="text-xs text-gray-400">A 1–2 sentence TL;DR — required when there's a description.</p>
                        <textarea id="edit-body-summary" name="body_summary" rows="2"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body_summary', $task->body_summary) }}</textarea>
                        <x-input-error :messages="$errors->get('body_summary')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="edit-body" value="Description (full)" />
                        <textarea id="edit-body" name="body" rows="4"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body', $task->body) }}</textarea>
                    </div>

                    <div>
                        <x-input-label for="edit-plan-summary" value="Plan summary" />
                        <p class="text-xs text-gray-400">A 1–2 sentence TL;DR — required when there's a plan.</p>
                        <textarea id="edit-plan-summary" name="plan_summary" rows="2"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('plan_summary', $task->plan_summary) }}</textarea>
                        <x-input-error :messages="$errors->get('plan_summary')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="edit-plan" value="Plan (full)" />
                        <textarea id="edit-plan" name="plan" rows="6"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('plan', $task->plan) }}</textarea>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save changes</x-primary-button>
                        <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

            {{-- main column --}}
            <div class="lg:col-span-2 space-y-6">

            {{-- lifecycle controls --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                <div class="space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Lifecycle</p>
                    <div class="flex items-center gap-2 flex-wrap">
                        @include('projects.partials.transitions', ['task' => $task, 'targets' => $task->allowedTransitions(), 'compact' => false])
                    </div>
                    <x-input-error :messages="$errors->get('status')" />
                </div>

                {{-- Hand this off to an agent --}}
                <div class="border-t border-gray-100 pt-3 space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Run with an agent</p>
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <div class="flex items-center gap-1.5">
                            @include('projects.partials.copy-prompt', ['task' => $task])
                            <span class="text-[11px] text-gray-400">— just this task</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            @include('projects.partials.loop-prompt', ['project' => $task->project])
                            <span class="text-[11px] text-gray-400">— loop every ready task on {{ $task->project->name }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- plan review walkthrough (only at the plan_review gate) --}}
            @if ($task->status === $T::STATUS_PLAN_REVIEW)
                @include('tasks.partials.plan-review', ['task' => $task])
            @endif

            {{-- plan-rework notes (what a plan review sent back) --}}
            @if ($task->plan_rework_notes)
                <div class="bg-amber-50 border border-amber-300 shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-amber-700 uppercase tracking-wide">Plan rework notes</p>
                    <x-markdown :content="$task->plan_rework_notes" />
                </div>
            @endif

            {{-- plan --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Plan</p>
                <x-detail-block title="Plan" :summary="$task->plan_summary" :full="$task->plan" empty="No plan yet." />
            </div>

            {{-- description --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Description</p>
                <x-detail-block title="Description" :summary="$task->body_summary" :full="$task->body" empty="No description." />
            </div>

            {{-- rework notes --}}
            @if ($task->rework_notes)
                <div class="bg-amber-50 border border-amber-300 shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-amber-700 uppercase tracking-wide">Rework notes</p>
                    <x-markdown :content="$task->rework_notes" />
                </div>
            @endif

            {{-- work sessions --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Work sessions</p>
                @forelse ($task->workSessions as $session)
                    <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0 space-y-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <a href="{{ route('work-sessions.show', $session) }}"
                               class="text-sm font-medium text-indigo-600 hover:underline truncate">{{ $session->title }}</a>
                            <span class="shrink-0 text-xs text-gray-400">{{ optional($session->occurred_on)->toFormattedDateString() }}</span>
                        </div>
                        <x-detail-block :title="$session->title" :summary="$session->body_summary" :full="$session->body" />
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No work sessions yet.</p>
                @endforelse
            </div>

            {{-- comments --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Comments</p>

                <div class="space-y-3">
                    @forelse ($task->comments as $comment)
                        <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-gray-800">{{ $comment->author }}</span>
                                <span class="text-xs text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                            <x-markdown :content="$comment->body" />
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 italic">No comments yet.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('tasks.comments.store', $task) }}" class="space-y-2">
                    @csrf
                    <textarea name="body" rows="3" placeholder="Leave a note…"
                              class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    <x-input-error :messages="$errors->get('body')" />
                    <div>
                        <x-primary-button class="!py-1.5 !text-xs">Comment</x-primary-button>
                    </div>
                </form>
            </div>

            </div> {{-- /main column --}}

            {{-- sidebar --}}
            <div class="space-y-6">

            {{-- linked reviews --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Linked reviews</p>
                @forelse ($task->reviews as $review)
                    @php
                        $cov = $review->coverage();
                        [$outcomeLabel, $outcomeClass] = match ($review->outcome) {
                            'approved' => ['approved', 'bg-emerald-100 text-emerald-700'],
                            'changes_requested' => ['changes requested', 'bg-amber-100 text-amber-700'],
                            default => [$review->status ?? 'open', 'bg-gray-100 text-gray-600'],
                        };
                    @endphp
                    <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <a href="{{ route('reviews.show', $review) }}" class="text-indigo-600 hover:underline truncate">{{ $review->title }}</a>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-gray-500">
                            <span class="rounded px-1.5 py-0.5 font-medium {{ $outcomeClass }}">{{ $outcomeLabel }}</span>
                            <span>{{ $cov['covered'] }}/{{ $cov['total'] }} files</span>
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No reviews cover this task yet.</p>
                @endforelse
            </div>

            {{-- dependencies (blocked by) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Blocked by</p>
                @forelse ($task->dependencies as $dep)
                    <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <a href="{{ route('tasks.show', $dep) }}" class="text-indigo-600 hover:underline truncate">{{ $dep->title }}</a>
                        <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-gray-100 text-gray-600">
                            {{ $T::LABELS[$dep->status] ?? $dep->status }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No dependencies.</p>
                @endforelse
            </div>

            {{-- activity log --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Activity</p>
                @forelse ($task->events as $event)
                    <div class="flex items-start gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-gray-100 text-gray-600 mt-0.5">{{ str_replace('_', ' ', $event->type) }}</span>
                        <div class="flex-1 min-w-0">
                            @if ($event->description)
                                <p class="text-gray-700">{{ $event->description }}</p>
                            @endif
                            <p class="text-xs text-gray-400">
                                {{ $event->actor ? $event->actor.' · ' : '' }}{{ $event->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No activity yet.</p>
                @endforelse
            </div>

            </div> {{-- /sidebar --}}

            </div> {{-- /grid --}}

        </div>
    </div>
</x-app-layout>
