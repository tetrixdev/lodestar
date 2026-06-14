<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Projects</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($projects->isEmpty())
                @include('partials.onboarding')
            @endif

            {{-- create --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-900 mb-1">{{ $projects->isEmpty() ? 'Create your first project' : 'New project' }}</h3>
                @if ($projects->isEmpty())
                    <p class="text-sm text-gray-500">A project groups the repos and tasks that share a goal. Give it a name to get going.</p>
                @endif
                <form method="POST" action="{{ route('projects.store') }}" class="mt-4 flex flex-col sm:flex-row gap-3">
                    @csrf
                    <input name="name" required placeholder="Project name"
                           class="flex-1 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    <input name="primary_goal" placeholder="Primary goal (optional)"
                           class="flex-1 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    <x-primary-button>Create</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            {{-- list --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @forelse ($projects as $project)
                    @php
                        $s = $summaries[$project->id] ?? ['live' => 0, 'done' => 0, 'phaseCounts' => [], 'overdue' => 0, 'nextDue' => null];
                        $live = $s['live'];
                        $done = $s['done'];
                        $progress = $live > 0 ? (int) round(($done / $live) * 100) : 0;
                    @endphp
                    <a href="{{ route('projects.show', $project) }}"
                       class="block bg-white shadow-sm sm:rounded-lg p-5 hover:shadow transition space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 truncate">{{ $project->name }}</div>
                                @if ($project->primary_goal)
                                    <div class="text-sm text-gray-500 line-clamp-1">{{ $project->primary_goal }}</div>
                                @endif
                            </div>
                            @if ($s['overdue'] > 0)
                                <span class="shrink-0 text-[11px] font-medium rounded px-2 py-0.5 bg-red-100 text-red-700">{{ $s['overdue'] }} overdue</span>
                            @endif
                        </div>

                        {{-- progress: done vs live --}}
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-[11px] text-gray-500">
                                <span>{{ $done }} / {{ $live }} done</span>
                                <span>{{ $progress }}%</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full bg-emerald-400" style="width: {{ $progress }}%"></div>
                            </div>
                        </div>

                        {{-- per-phase counts --}}
                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-500">
                            @foreach ($phases as $key => $phase)
                                <span class="inline-flex items-center gap-1">
                                    <span class="font-medium text-gray-700">{{ $s['phaseCounts'][$key] ?? 0 }}</span>
                                    {{ $phase['label'] }}
                                </span>
                            @endforeach
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-gray-400 pt-1 border-t border-gray-50">
                            <span>{{ $project->tasks_count }} total</span>
                            @if ($s['nextDue'])
                                <span>Next due {{ $s['nextDue']->toFormattedDateString() }}</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <p class="sm:col-span-2 p-5 text-gray-500 bg-white shadow-sm sm:rounded-lg">No projects yet — create one above.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
