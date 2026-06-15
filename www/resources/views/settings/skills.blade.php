<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Skills') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-1">
                <p class="text-sm text-gray-600">
                    Skills are <strong>layered</strong>: each phase prompt is composed across scopes
                    (system &rarr; team &rarr; personal &rarr; project). Below is the effective prompt your
                    loop receives for each phase, with the layers that contributed.
                </p>
                <p class="text-xs text-gray-400">
                    Proposing &amp; approving changes, version history, the overwrite toggle and named
                    skills are coming to a filterable overview.
                </p>
            </div>

            @foreach ($phases as $phase)
                @php $c = $composed[$phase]; @endphp
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3" x-data="{ open: false }">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-800">{{ $phaseLabels[$phase] ?? $phase }}</h3>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @forelse ($c['layers'] as $layer)
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-indigo-50 text-indigo-700">
                                    {{ $layer['scope'] }}
                                    @if ($layer['mode'] === \App\Models\Skill::MODE_OVERWRITE)
                                        <span class="text-amber-600" title="overwrites the layers above it">&#9888;</span>
                                    @endif
                                    <span class="text-indigo-400">v{{ $layer['version'] }}</span>
                                </span>
                            @empty
                                <span class="text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-500">No layers</span>
                            @endforelse
                        </div>
                    </div>

                    @if ($c['body'] !== '')
                        <button type="button" @click="open = !open"
                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                            <span x-text="open ? 'Hide composed prompt' : 'Show composed prompt'"></span>
                        </button>
                        <pre x-show="open" x-cloak
                             class="mt-1 whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-50 rounded-md p-3 max-h-[60vh] overflow-y-auto">{{ $c['body'] }}</pre>
                    @else
                        <p class="text-sm text-gray-400 italic">No prompt composed for this phase.</p>
                    @endif
                </div>
            @endforeach

        </div>
    </div>
</x-app-layout>
