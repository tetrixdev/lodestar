<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Skills') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status') === 'skill-saved')
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">Skill saved.</div>
            @elseif (session('status') === 'skill-deleted')
                <div class="p-3 bg-gray-100 text-gray-700 rounded-lg text-sm">Fork deleted.</div>
            @endif

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
                                <div>
                                    <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5">{{ $phaseLabels[$phase] ?? $phase }}</span>
                                    <span class="ml-2 text-sm font-medium text-gray-900">{{ $skill?->title ?? '— none seeded —' }}</span>
                                    @if ($skill)<span class="ml-1 text-xs text-gray-400">v{{ $skill->version }}</span>@endif
                                </div>
                                @if ($skill)
                                    <form method="POST" action="{{ route('skills.duplicate', $skill) }}">
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
                                <div>
                                    <span class="inline-block text-[10px] font-medium uppercase tracking-wide text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">{{ $phaseLabels[$fork->key] ?? $fork->key }}</span>
                                    <span class="ml-2 text-sm font-medium text-gray-900">{{ $fork->title }}</span>
                                </div>
                                <div class="flex items-center gap-3">
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
