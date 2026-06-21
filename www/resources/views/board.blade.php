<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Board</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @include('partials.onboarding')

            {{-- needs-you strip: cross-project signals (folded in from the old dashboard) --}}
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mr-1">Needs you</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 border border-amber-200 px-2.5 py-1 text-amber-800">
                    <span class="font-semibold">{{ $needs['plans'] }}</span> plan(s) to approve
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 border border-sky-200 px-2.5 py-1 text-sky-800">
                    <span class="font-semibold">{{ $needs['reviews'] }}</span> review(s) waiting
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 border border-rose-200 px-2.5 py-1 text-rose-800">
                    <span class="font-semibold">{{ $needs['overdue'] }}</span> overdue
                </span>
            </div>

            {{-- toolbar: project filter + create-deliverable --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <form method="GET" action="{{ route('board') }}" class="flex items-center gap-2">
                    <label for="project-filter" class="text-sm text-gray-500">Project</label>
                    <x-select id="project-filter" name="project" onchange="this.form.submit()"
                            class="sm:w-56">
                        <option value="all" @selected($selectedId === null)>All projects</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}" @selected($selectedId === $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </x-select>
                </form>

                @if ($selectedId !== null)
                    @php $selProject = $projects->firstWhere('id', $selectedId); @endphp
                    <form method="POST" action="{{ route('deliverables.store', $selProject) }}" class="flex items-center gap-2 sm:ml-auto">
                        @csrf
                        <input name="title" type="text" placeholder="New deliverable on {{ $selProject->name }}…" required
                               class="flex-1 sm:w-72 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                        <x-primary-button class="!py-2 !text-xs whitespace-nowrap">+ Deliverable</x-primary-button>
                    </form>
                @else
                    <span class="text-xs text-gray-400 sm:ml-auto">Pick a single project to add a deliverable.</span>
                @endif
            </div>

            <x-input-error :messages="$errors->get('status')" />
            <x-input-error :messages="$errors->get('title')" />

            {{-- 5 phase columns; the board is deliverable-only — deliverable cards in each --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-start">
                @foreach ($phases as $phaseKey => $phase)
                    @php
                        $dels = $deliverableCardsByPhase[$phaseKey] ?? collect();
                        $count = $dels->count();
                    @endphp
                    <div class="rounded-lg bg-gray-50 border border-gray-100 p-2 space-y-2">
                        <div class="flex items-center justify-between px-1">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $phase['label'] }}</h3>
                            <span class="text-[10px] text-gray-400">{{ $count }}</span>
                        </div>

                        <div class="space-y-2">
                            @foreach ($dels as $card)
                                @include('partials.board.deliverable-card', ['deliverable' => $card['deliverable'], 'tasks' => $card['tasks']])
                            @endforeach

                            @if ($count === 0)
                                <p class="px-1 py-3 text-center text-[11px] text-gray-300">—</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
