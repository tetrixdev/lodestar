<x-app-layout>
    @php
        $D = \App\Models\Deliverable::class;
        $T = \App\Models\Task::class;
        $statusLabel = $D::LABELS[$deliverable->status] ?? $deliverable->status;
        $unanswered = $deliverable->questions->whereNull('answered_at')->count();
        $planReviewTasks = $deliverable->tasks->where('status', $T::STATUS_PLAN_REVIEW);
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
                 x-data="{ editing: @js($errors->hasAny(['title','category','base_branch','comparison_ref','concept','concept_summary','body','body_summary'])) }">
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
                    <span class="min-w-0 break-all" title="Merge target">Base <code class="bg-gray-100 px-1 rounded">{{ $deliverable->base_branch ?? '—' }}</code></span>
                    <span class="min-w-0 break-all" title="Review diff-base">Diff <code class="bg-gray-100 px-1 rounded">{{ $deliverable->comparison_ref ?? '—' }}</code></span>
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
                            <x-input-label for="d-base" value="Base branch (merge target)" />
                            <x-text-input id="d-base" name="base_branch" type="text" class="mt-1 block w-full" :value="old('base_branch', $deliverable->base_branch)" placeholder="main" />
                        </div>
                        <div>
                            <x-input-label for="d-comparison" value="Comparison ref (review diff-base)" />
                            <x-text-input id="d-comparison" name="comparison_ref" type="text" class="mt-1 block w-full" :value="old('comparison_ref', $deliverable->comparison_ref)" placeholder="main" />
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

                {{-- plan-review walkthrough: one section per task awaiting plan approval --}}
                @if ($planReviewTasks->isNotEmpty())
                    <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4 border-l-4 border-amber-300">
                        <div>
                            <p class="text-[11px] font-medium text-amber-700 uppercase tracking-wide">Plan review — {{ $planReviewTasks->count() }} task(s)</p>
                            <p class="text-xs text-gray-500">Approve each task's plan to send it to the build queue, or request changes to send it back to planning. Tasks are decided individually — approved ones can start while others wait.</p>
                        </div>
                        @foreach ($planReviewTasks as $task)
                            <div class="rounded-lg border border-gray-200 p-4 space-y-3" x-data="{ changes: false }">
                                <a href="{{ route('tasks.show', $task) }}" class="text-sm font-medium text-gray-800 hover:text-indigo-700">
                                    <span class="text-gray-400">{{ sprintf('T%02d', $task->sub_id) }}</span> {{ $task->title }}
                                </a>
                                <div>
                                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Client-facing</p>
                                    <x-detail-block title="Client-facing" :summary="$task->body_summary" :full="$task->body" empty="—" />
                                </div>
                                <div>
                                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Technical / architecture</p>
                                    <x-detail-block title="Technical" :summary="$task->plan_summary" :full="$task->plan" empty="—" />
                                </div>
                                <div class="flex items-center gap-2 pt-1 border-t border-gray-100">
                                    <form method="POST" action="{{ route('tasks.plan-decision', $task) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="decision" value="approve">
                                        <button class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">Approve plan</button>
                                    </form>
                                    <button type="button" @click="changes = !changes" class="text-xs font-medium text-amber-700 hover:text-amber-900 px-2 py-1.5">Request changes</button>
                                    <form x-show="changes" x-cloak method="POST" action="{{ route('tasks.plan-decision', $task) }}" class="flex-1 flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="decision" value="changes">
                                        <input name="note" type="text" placeholder="What to change…"
                                               class="flex-1 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                                        <button class="rounded-md border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-50">Send back</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
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
                {{-- open questions --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Open questions</p>
                        @if ($unanswered > 0)
                            <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-700">{{ $unanswered }} unanswered</span>
                        @endif
                    </div>
                    @forelse ($deliverable->questions as $q)
                        <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0 space-y-1.5">
                            <p class="text-sm text-gray-800">{{ $q->question }}</p>
                            <form method="POST" action="{{ route('deliverables.questions.answer', [$deliverable, $q->id]) }}" class="space-y-1.5">
                                @csrf @method('PATCH')
                                <textarea name="answer" rows="2" placeholder="Answer…"
                                          class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ $q->answer }}</textarea>
                                <div class="flex items-center justify-between">
                                    <span class="text-[10px] {{ $q->answered_at ? 'text-emerald-600' : 'text-amber-600' }}">{{ $q->answered_at ? 'answered' : 'unanswered' }}</span>
                                    <button class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Save</button>
                                </div>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 italic">No open questions.</p>
                    @endforelse

                    <form method="POST" action="{{ route('deliverables.questions.store', $deliverable) }}" class="flex items-center gap-2 pt-1">
                        @csrf
                        <input name="question" type="text" placeholder="Add a question…" required
                               class="flex-1 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                        <button class="shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-800">Add</button>
                    </form>
                </div>

                {{-- reviews --}}
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
