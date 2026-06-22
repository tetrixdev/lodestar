<x-app-layout>
    @php
        $D = \App\Models\Deliverable::class;
        $T = \App\Models\Task::class;
        $R = \App\Models\Review::class;
        $statusLabel = $D::LABELS[$deliverable->status] ?? $deliverable->status;

        // Per-task plan + functional reviews, for the two collapsible review
        // sections below. A plan review is a normal Review of review_type=plan;
        // the functional review is the per-task human gate.
        $planReviews = $deliverable->tasks
            ->map(fn ($t) => [$t, $t->reviews->where('review_type', $R::TYPE_PLAN)->sortByDesc('id')->first()])
            ->filter(fn ($pair) => $pair[1] !== null);
        $functionalReviews = $deliverable->tasks
            ->map(fn ($t) => [$t, $t->reviews->where('review_type', $R::TYPE_FUNCTIONAL)->sortByDesc('id')->first()])
            ->filter(fn ($pair) => $pair[1] !== null);
    @endphp

    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Board', 'url' => route('board')],
                ['label' => $deliverable->project->name, 'url' => route('projects.show', $deliverable->project)],
                ['label' => 'Deliverable: '.$deliverable->title],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                <span class="text-gray-400">{{ sprintf('D%06d', $deliverable->id) }}</span> {{ $deliverable->title }}
            </h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- header / meta --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3"
                 x-data="{ editing: @js($errors->hasAny(['title','category','base_branch','concept','concept_summary','body','body_summary'])) }">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-block text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-700">{{ $statusLabel }}</span>
                        @if ($deliverable->category)
                            <span class="inline-block text-[11px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-2 py-0.5">{{ $deliverable->category }}</span>
                        @endif
                        <span class="text-xs text-gray-500">{{ $deliverable->tasks->count() }} task(s)</span>
                    </div>
                    <button type="button" @click="editing = !editing"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        <span x-text="editing ? 'Cancel' : 'Edit'"></span>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-gray-600" x-show="!editing">
                    @if ($deliverable->branch)
                        <span class="min-w-0 break-all">Branch <code class="bg-gray-100 px-1 rounded">{{ $deliverable->branch }}</code></span>
                    @endif
                    <span class="min-w-0 break-all">Base <code class="bg-gray-100 px-1 rounded">{{ $deliverable->base_branch ?? '—' }}</code></span>
                </div>

                {{-- edit panel --}}
                <form x-show="editing" x-cloak method="POST" action="{{ route('deliverables.update', $deliverable) }}" class="space-y-4 pt-1">
                    @csrf @method('PATCH')
                    <div>
                        <x-input-label for="d-title" value="Title" />
                        <x-text-input id="d-title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $deliverable->title)" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="d-category" value="Category" />
                            <x-text-input id="d-category" name="category" type="text" class="mt-1 block w-full" :value="old('category', $deliverable->category)" />
                        </div>
                        <div>
                            <x-input-label for="d-base" value="Base branch" />
                            <x-text-input id="d-base" name="base_branch" type="text" class="mt-1 block w-full" :value="old('base_branch', $deliverable->base_branch)" placeholder="main" />
                        </div>
                    </div>
                    @foreach (['concept' => 'Concept (raw)', 'body' => 'Scope'] as $field => $label)
                        <div>
                            <x-input-label :for="'d-'.$field.'-summary'" :value="$label.' summary'" />
                            <textarea id="d-{{ $field }}-summary" name="{{ $field }}_summary" rows="2"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old($field.'_summary', $deliverable->{$field.'_summary'}) }}</textarea>
                            <x-input-error :messages="$errors->get($field.'_summary')" class="mt-1" />
                            <textarea id="d-{{ $field }}" name="{{ $field }}" rows="4" placeholder="{{ $label }} (full)"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old($field, $deliverable->{$field}) }}</textarea>
                        </div>
                    @endforeach
                    <div class="flex items-center gap-3">
                        <x-primary-button>Save</x-primary-button>
                        <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 space-y-6">

                {{-- lifecycle (status is DERIVED from the tasks + review outcomes — no manual advancement) --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Status</p>
                    <p class="text-sm text-gray-700">
                        <span class="inline-block text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-700">{{ $statusLabel }}</span>
                        <span class="text-gray-400">— derived from its tasks; it advances on its own and through review decisions.</span>
                    </p>
                </div>

                {{-- Plan review — collapsed by default. Each task's plan review is a
                     normal Review (review_type=plan); the human walks/concludes it on
                     its own page. --}}
                @if ($planReviews->isNotEmpty())
                    <div class="bg-white shadow-sm sm:rounded-lg p-5" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-2 text-left">
                            <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Plan review — {{ $planReviews->count() }} task(s)</span>
                            <svg class="size-4 text-gray-400 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        </button>
                        <ul x-show="open" x-collapse x-cloak class="mt-3 space-y-2">
                            @foreach ($planReviews as [$task, $review])
                                <li class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                                    <a href="{{ route('reviews.show', $review) }}" class="min-w-0 truncate text-indigo-600 hover:underline">
                                        <span class="text-gray-400">{{ sprintf('T%02d', $task->sub_id) }}</span> {{ $task->title }}
                                    </a>
                                    <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 {{ $review->outcome ? ($review->outcome === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700') : 'bg-amber-100 text-amber-700' }}">
                                        {{ $review->outcome ? str_replace('_', ' ', $review->outcome) : ($review->plan_incomplete ? 'incomplete' : 'open') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Functional review — collapsed by default (the per-task human gate). --}}
                @if ($functionalReviews->isNotEmpty())
                    <div class="bg-white shadow-sm sm:rounded-lg p-5" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-2 text-left">
                            <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Functional review — {{ $functionalReviews->count() }} task(s)</span>
                            <svg class="size-4 text-gray-400 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        </button>
                        <ul x-show="open" x-collapse x-cloak class="mt-3 space-y-2">
                            @foreach ($functionalReviews as [$task, $review])
                                <li class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                                    <a href="{{ route('reviews.show', $review) }}" class="min-w-0 truncate text-indigo-600 hover:underline">
                                        <span class="text-gray-400">{{ sprintf('T%02d', $task->sub_id) }}</span> {{ $task->title }}
                                    </a>
                                    <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 {{ $review->outcome ? ($review->outcome === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700') : 'bg-amber-100 text-amber-700' }}">
                                        {{ $review->outcome ? str_replace('_', ' ', $review->outcome) : 'open' }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- scope (a deliverable is a scope, not a plan — the plan is its tasks) --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Concept (as written)</p>
                    <x-detail-block title="Concept" :summary="$deliverable->concept_summary" :full="$deliverable->concept" empty="No concept yet." />
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Scope</p>
                    <x-detail-block title="Scope" :summary="$deliverable->body_summary" :full="$deliverable->body" empty="No refined scope yet." />
                </div>

                {{-- child tasks --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Tasks</p>
                    @forelse ($deliverable->tasks as $task)
                        <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                            <a href="{{ route('tasks.show', $task) }}" class="min-w-0 truncate text-indigo-600 hover:underline">
                                <span class="text-gray-400">{{ sprintf('T%02d', $task->sub_id) }}</span> {{ $task->title }}
                                @if ($task->is_corrective)<span class="ml-1 text-[10px] uppercase tracking-wide text-amber-600">corrective</span>@endif
                            </a>
                            <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-gray-100 text-gray-600">
                                {{ $T::LABELS[$task->status] ?? $task->status }}
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 italic">No tasks yet. The plan decomposes into tasks; add one below to test.</p>
                    @endforelse

                    <form method="POST" action="{{ route('deliverables.tasks.store', $deliverable) }}" class="flex items-center gap-2 pt-1">
                        @csrf
                        <input name="title" type="text" placeholder="New task title…" required
                               class="flex-1 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                        <x-primary-button class="!py-1.5 !text-xs">Add task</x-primary-button>
                    </form>
                </div>
            </div>

            {{-- sidebar --}}
            <div class="space-y-6">
                {{-- Open questions are no longer a deliverable-level mechanism — they
                     live as findings on each task's plan review (see the Plan review
                     section). --}}

                {{-- deliverable-level reviews --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Deliverable reviews</p>
                    @forelse ($deliverable->reviews as $review)
                        <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                            <a href="{{ route('reviews.show', $review) }}" class="truncate text-indigo-600 hover:underline">{{ $review->title }}</a>
                            <span class="shrink-0 text-[10px] uppercase tracking-wide text-gray-500">{{ $review->review_type }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 italic">No deliverable-level reviews yet.</p>
                    @endforelse
                </div>
            </div>
            </div>
        </div>
    </div>
</x-app-layout>
