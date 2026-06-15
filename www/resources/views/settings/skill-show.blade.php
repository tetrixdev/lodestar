<x-app-layout>
    @php $M = \App\Models\Skill::class; $V = \App\Models\SkillVersion::class; @endphp

    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('skills.index') }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                <span class="text-gray-400">{{ $skill->scope }}</span> · {{ $skill->key }}
                @if ($skill->owner)<span class="text-gray-400 text-base">— {{ $skill->owner->name }}</span>@endif
            </h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">
                    @switch(session('status'))
                        @case('skill-published') Change published — it's live now. @break
                        @case('skill-proposed') Proposed — an approver will review it. @break
                        @case('skill-approved') Approved — it's live now. @break
                        @case('skill-rejected') Proposal rejected. @break
                        @case('skill-mode-changed') Layer mode changed. @break
                        @default Done.
                    @endswitch
                </div>
            @endif

            {{-- overwrite warning + mode control --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3" x-data="{ mode: '{{ $skill->mode }}' }">
                @if ($skill->mode === $M::MODE_OVERWRITE)
                    <div class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800">
                        <span class="text-lg leading-none">&#9888;</span>
                        <p><strong>This layer OVERWRITES.</strong> When composed, it discards everything above it
                        (system / team / project) and starts the prompt from this layer's body. Only the layers
                        <em>below</em> it (toward personal) still append.</p>
                    </div>
                @else
                    <p class="text-sm text-gray-600"><strong>Append layer.</strong> Its body is added onto the layers above it.</p>
                @endif

                @if ($canApprove)
                    <form method="POST" action="{{ route('skills.mode', $skill) }}"
                          @submit="if (mode === '{{ $M::MODE_APPEND }}' && ! confirm('Switch to OVERWRITE? When composed, this layer will DISCARD everything above it (system/team/project) and become the base. Continue?')) $event.preventDefault()">
                        @csrf @method('PATCH')
                        <button class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                            @if ($skill->mode === $M::MODE_OVERWRITE) Switch to append @else Switch to overwrite (full override) @endif
                        </button>
                    </form>
                @endif
            </div>

            {{-- active version --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Active version</p>
                @if ($skill->activeVersion)
                    <p class="text-xs text-gray-500">v{{ $skill->activeVersion->version }} · {{ $skill->activeVersion->title }}</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-50 rounded-md p-3 max-h-[50vh] overflow-y-auto">{{ $skill->activeVersion->body }}</pre>
                @else
                    <p class="text-sm text-gray-400 italic">No active version — nothing from this layer composes yet.</p>
                @endif
            </div>

            {{-- propose a change --}}
            @if ($canPropose)
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Propose a change</p>
                    <p class="text-xs text-gray-500">
                        @if ($canApprove) You can approve this scope, so your change goes live immediately.
                        @else Your change is recorded as a proposal for an approver. @endif
                    </p>
                    <form method="POST" action="{{ route('skills.propose') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="{{ $skill->scope }}">
                        <input type="hidden" name="key" value="{{ $skill->key }}">
                        @if ($skill->scope === $M::SCOPE_TEAM)
                            <input type="hidden" name="team_id" value="{{ $skill->owner_id }}">
                        @elseif ($skill->scope === $M::SCOPE_PROJECT)
                            <input type="hidden" name="project_id" value="{{ $skill->owner_id }}">
                        @endif
                        <div>
                            <x-input-label for="p-title" value="Title" />
                            <x-text-input id="p-title" name="title" type="text" class="mt-1 block w-full"
                                          :value="old('title', $skill->activeVersion?->title ?? $skill->title)" />
                            <x-input-error :messages="$errors->get('title')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="p-summary" value="Summary (one line — for the main catalog)" />
                            <x-text-input id="p-summary" name="summary" type="text" class="mt-1 block w-full"
                                          :value="old('summary', $skill->summary)" placeholder="What it's for / when to use it" />
                            <x-input-error :messages="$errors->get('summary')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="p-body" value="Prompt body (markdown)" />
                            <textarea id="p-body" name="body" rows="8"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body', $skill->activeVersion?->body) }}</textarea>
                            <x-input-error :messages="$errors->get('body')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="p-note" value="Note for the approver (optional)" />
                            <textarea id="p-note" name="note" rows="2"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('note') }}</textarea>
                        </div>
                        <x-primary-button>{{ $canApprove ? 'Publish' : 'Propose' }}</x-primary-button>
                    </form>
                </div>
            @endif

            {{-- compare versions + decide on a proposal --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3" x-data="{ editing: false }">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Compare &amp; review</p>
                @if ($versions->count() < 2)
                    <p class="text-sm text-gray-400 italic">Need at least two versions to compare.</p>
                @else
                    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">From</label>
                            <select name="a" class="h-10 rounded-md border-gray-300 text-sm">
                                @foreach ($versions as $v)
                                    <option value="{{ $v->id }}" @selected($diffA && $diffA->is($v))>v{{ $v->version }} · {{ $v->status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">To</label>
                            <select name="b" class="h-10 rounded-md border-gray-300 text-sm">
                                @foreach ($versions as $v)
                                    <option value="{{ $v->id }}" @selected($diffB && $diffB->is($v))>v{{ $v->version }} · {{ $v->status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-primary-button class="!h-10">Diff</x-primary-button>
                    </form>

                    @if ($diff)
                        <div class="font-mono text-xs rounded-md border border-gray-100 overflow-x-auto">
                            @foreach ($diff as $row)
                                <div class="px-3 py-0.5 whitespace-pre-wrap break-words
                                    @if ($row['op'] === 'add') bg-green-50 text-green-800
                                    @elseif ($row['op'] === 'del') bg-red-50 text-red-800
                                    @else text-gray-600 @endif">{{ $row['op'] === 'add' ? '+ ' : ($row['op'] === 'del' ? '- ' : '  ') }}{{ $row['text'] }}</div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400 italic">Pick two different versions to see the diff.</p>
                    @endif

                    {{-- decide on the "To" version when it's a proposal you can approve --}}
                    @if ($canApprove && $diffB && $diffB->isProposed())
                        <div class="border-t border-gray-100 pt-3 space-y-3">
                            <p class="text-xs text-gray-500">Decide on <span class="font-medium">v{{ $diffB->version }}</span> (proposed{{ $diffB->proposed_by_ai ? ', by AI' : '' }}){{ $diffB->note ? ' — '.$diffB->note : '' }}:</p>
                            <div class="flex flex-wrap items-center gap-2" x-show="!editing">
                                <form method="POST" action="{{ route('skills.versions.approve', $diffB) }}">
                                    @csrf
                                    <button class="rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-500">Approve</button>
                                </form>
                                <button type="button" @click="editing = true" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Approve with edits</button>
                                <form method="POST" action="{{ route('skills.versions.reject', $diffB) }}">
                                    @csrf
                                    <button class="rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Reject</button>
                                </form>
                            </div>

                            {{-- approve-with-edits: publishes your amended body as a new active version, archiving this proposal --}}
                            <form x-show="editing" x-cloak method="POST" action="{{ route('skills.versions.approveEdits', $diffB) }}" class="space-y-3">
                                @csrf
                                <p class="text-xs text-gray-500">Your edits publish a new active version; this proposal is archived as "amended into" it.</p>
                                <div>
                                    <x-input-label for="ae-title" value="Title" />
                                    <x-text-input id="ae-title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $diffB->title)" />
                                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ae-summary" value="Summary (one line)" />
                                    <x-text-input id="ae-summary" name="summary" type="text" class="mt-1 block w-full" :value="old('summary', $skill->summary)" />
                                </div>
                                <div>
                                    <x-input-label for="ae-body" value="Body (markdown)" />
                                    <textarea id="ae-body" name="body" rows="10"
                                              class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body', $diffB->body) }}</textarea>
                                    <x-input-error :messages="$errors->get('body')" class="mt-1" />
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-primary-button>Publish amended version</x-primary-button>
                                    <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endif
            </div>

            {{-- version history (read-only log) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Version history</p>
                <p class="text-xs text-gray-400">Review proposals in <span class="font-medium">Compare &amp; review</span> above (pick the proposal as “To”).</p>
                @foreach ($versions as $v)
                    <div class="flex items-center gap-2 text-sm border-b border-gray-100 py-2 last:border-0 min-w-0">
                        <span class="text-gray-700 font-medium">v{{ $v->version }}</span>
                        <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5
                            @class([
                                'bg-green-100 text-green-800' => $v->status === $V::STATUS_ACTIVE,
                                'bg-amber-100 text-amber-800' => $v->status === $V::STATUS_PROPOSED,
                                'bg-gray-100 text-gray-500' => $v->status === $V::STATUS_ARCHIVED,
                                'bg-red-100 text-red-700' => $v->status === $V::STATUS_REJECTED,
                            ])">{{ $v->status }}</span>
                        <span class="text-xs text-gray-400 truncate">
                            {{ $v->author?->name ?? 'system' }}@if ($v->proposed_by_ai) <span class="text-indigo-500">(AI)</span>@endif
                            · {{ $v->created_at->diffForHumans() }}@if ($v->note) · {{ $v->note }}@endif
                        </span>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
