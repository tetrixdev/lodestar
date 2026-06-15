<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Skills') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">
                    @switch(session('status'))
                        @case('skill-published') Change published — it's live now. @break
                        @case('skill-proposed') Proposed — an approver will review it. @break
                        @case('skill-approved') Approved — it's live now. @break
                        @case('skill-rejected') Proposal rejected. @break
                        @case('skill-mode-changed') Layer mode changed. @break
                        @default Saved.
                    @endswitch
                </div>
            @endif

            {{-- Effective composed prompt per phase --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <div>
                    <h3 class="font-semibold text-gray-800">Effective prompts</h3>
                    <p class="text-xs text-gray-500">
                        How each phase composes for you right now (system &rarr; team &rarr; project &rarr;
                        personal; personal has the final say). Layers shown in order.
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach ($phases as $phase)
                        @php $c = $composed[$phase]; @endphp
                        <div class="border border-gray-100 rounded-md p-3 space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-gray-700">{{ $phaseLabels[$phase] ?? $phase }}</span>
                                <div class="flex flex-wrap items-center gap-1 justify-end">
                                    @forelse ($c['layers'] as $layer)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-50 text-indigo-700">
                                            {{ $layer['scope'] }}@if ($layer['mode'] === \App\Models\Skill::MODE_OVERWRITE)<span class="text-amber-600" title="overwrites the layers above it">&#9888;</span>@endif
                                        </span>
                                    @empty
                                        <span class="text-[10px] text-gray-400">— none —</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Filterable layer list --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                <h3 class="font-semibold text-gray-800">All skill layers</h3>

                <form method="GET" class="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
                    <select name="scope" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any scope</option>
                        @foreach ($scopes as $s)
                            <option value="{{ $s }}" @selected(($filters['scope'] ?? '') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <select name="key" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any phase/key</option>
                        @foreach ($phases as $p)
                            <option value="{{ $p }}" @selected(($filters['key'] ?? '') === $p)>{{ $phaseLabels[$p] ?? $p }}</option>
                        @endforeach
                    </select>
                    <select name="team_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any team</option>
                        @foreach ($teams as $t)
                            <option value="{{ $t->id }}" @selected((string) ($filters['team_id'] ?? '') === (string) $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <select name="project_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any project</option>
                        @foreach ($projects as $pr)
                            <option value="{{ $pr->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $pr->id)>{{ $pr->name }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any status</option>
                        @foreach ($statuses as $st)
                            <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ ucfirst($st) }}</option>
                        @endforeach
                    </select>
                    <div class="col-span-2 md:col-span-5 flex gap-2">
                        <x-primary-button class="!py-1.5 !text-xs">Filter</x-primary-button>
                        <a href="{{ route('skills.index') }}" class="text-xs text-gray-500 hover:text-gray-700 self-center">Clear</a>
                    </div>
                </form>

                <div class="divide-y divide-gray-100">
                    @forelse ($slots as $slot)
                        <a href="{{ route('skills.show', $slot) }}" class="flex items-center justify-between gap-3 py-2.5 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-700">{{ $slot->scope }}</span>
                                <span class="font-medium text-gray-800 truncate">{{ $slot->key }}</span>
                                @if ($slot->owner)
                                    <span class="text-xs text-gray-400 truncate">{{ $slot->owner->name }}</span>
                                @endif
                                @if ($slot->mode === \App\Models\Skill::MODE_OVERWRITE)
                                    <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-800">&#9888; overwrite</span>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2 text-xs text-gray-500">
                                @if ($slot->proposed_count > 0)
                                    <span class="rounded-full px-2 py-0.5 bg-amber-100 text-amber-800 font-medium">{{ $slot->proposed_count }} proposed</span>
                                @endif
                                <span>{{ $slot->activeVersion ? 'v'.$slot->activeVersion->version : 'no active' }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 italic py-2">No skill layers match.</p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
