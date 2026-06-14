<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Skills') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status') === 'skill-saved')
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">Skill saved.</div>
            @elseif (session('status') === 'skill-deleted')
                <div class="p-3 bg-gray-100 text-gray-700 rounded-lg text-sm">Fork deleted.</div>
            @elseif (session('status') === 'skill-bound')
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">Phase binding updated.</div>
            @elseif (session('status') === 'skill-unbound')
                <div class="p-3 bg-gray-100 text-gray-700 rounded-lg text-sm">Reset to the system skill.</div>
            @endif

            {{-- Your loop: which skill runs for each phase. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Your loop</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Choose which skill each phase runs. With no choice, the loop runs the
                        current system skill.
                    </p>
                </header>

                <div class="mt-6 space-y-4">
                    @foreach ($phases as $phase)
                        @php
                            $system = $systemSkills[$phase];
                            $running = $effective[$phase];
                            $binding = $bindings[$phase] ?? null;
                            $phaseForks = $forksByPhase[$phase] ?? collect();
                        @endphp
                        <div class="border rounded-lg p-4">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">{{ $phaseLabels[$phase] ?? $phase }}</span>
                                    <span class="ml-2 text-sm text-gray-500">runs</span>
                                    <span class="ml-1 text-sm font-medium text-gray-900">{{ $running?->title ?? '— none seeded —' }}</span>
                                    @if ($running)
                                        @if ($running->isSystem())
                                            <span class="ml-1 text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">System</span>
                                        @else
                                            <span class="ml-1 text-[10px] font-medium uppercase tracking-wide text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">Fork</span>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="POST" action="{{ route('skills.bind') }}" class="flex items-center gap-2 min-w-0">
                                        @csrf
                                        <input type="hidden" name="phase" value="{{ $phase }}">
                                        <select name="skill_id" class="min-w-0 flex-1 sm:flex-none text-sm border-gray-300 rounded-md focus:border-indigo-500 focus:ring-indigo-500">
                                            @if ($system)
                                                <option value="{{ $system->id }}" @selected($running && $running->is($system))>System: {{ $system->title }}</option>
                                            @endif
                                            @foreach ($phaseForks as $fork)
                                                <option value="{{ $fork->id }}" @selected($binding && (int) $binding->skill_id === $fork->id)>Fork: {{ $fork->title }}</option>
                                            @endforeach
                                        </select>
                                        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-800" @disabled(! $system && $phaseForks->isEmpty())>Bind</button>
                                    </form>

                                    @if ($binding)
                                        <form method="POST" action="{{ route('skills.unbind', $phase) }}">
                                            @csrf @method('DELETE')
                                            <button class="text-sm text-gray-500 hover:text-gray-700">Reset to system</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- System skills: the prompts that ship with Lodestar (read-only). --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">System skills</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        The prompt that drives each loop phase. These are read-only —
                        duplicate one to make an editable copy you own.
                    </p>
                </header>

                <div class="mt-6 space-y-4">
                    @foreach ($phases as $phase)
                        @php $skill = $systemSkills[$phase]; @endphp
                        <div class="border rounded-lg p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">{{ $phaseLabels[$phase] ?? $phase }}</span>
                                    <span class="ml-2 text-sm font-medium text-gray-900">{{ $skill?->title ?? '— none seeded —' }}</span>
                                    @if ($skill)<span class="ml-1 text-xs text-gray-400">v{{ $skill->version }}</span>@endif
                                </div>
                                @if ($skill)
                                    <form method="POST" action="{{ route('skills.duplicate', $skill) }}" class="shrink-0">
                                        @csrf
                                        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Duplicate to customize</button>
                                    </form>
                                @endif
                            </div>
                            @if ($skill)
                                <pre class="mt-3 p-3 bg-gray-50 rounded text-xs text-gray-700 whitespace-pre-wrap">{{ $skill->body }}</pre>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- The user's editable forks. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Your skills</h3>
                    <p class="mt-1 text-sm text-gray-600">Forks you can edit.</p>
                </header>

                @if ($forks->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">No forks yet — duplicate a system skill above to start one.</p>
                @else
                    <ul class="mt-4 divide-y">
                        @foreach ($forks as $fork)
                            <li class="py-3 flex items-center justify-between gap-3">
                                <div class="min-w-0 truncate">
                                    <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">{{ $phaseLabels[$fork->key] ?? $fork->key }}</span>
                                    <span class="ml-2 text-sm font-medium text-gray-900">{{ $fork->title }}</span>
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <a href="{{ route('skills.edit', $fork) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Edit</a>
                                    <form method="POST" action="{{ route('skills.destroy', $fork) }}"
                                          onsubmit="return confirm('Delete this fork?')">
                                        @csrf @method('DELETE')
                                        <button class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
