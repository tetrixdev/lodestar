<x-app-layout>
    @php
        $M = \App\Models\Playbook::class;
        $V = \App\Models\PlaybookVersion::class;
        $isPhase = $M::isPhase($playbook->key); // phase keys aren't catalogued → summary N/A
    @endphp

    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Settings', 'url' => route('settings.index')],
                ['label' => 'Playbooks', 'url' => route('playbooks.index')],
                ['label' => $playbook->scope.' · '.$playbook->key],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                <span class="text-gray-400">{{ $playbook->scope }}</span> · {{ $playbook->key }}
                @if ($playbook->owner)<span class="text-gray-400 text-base">— {{ $playbook->owner->name }}</span>@endif
            </h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">
                    @switch(session('status'))
                        @case('playbook-published') Change published — it's live now. @break
                        @case('playbook-proposed') Proposed — an approver will review it. @break
                        @case('playbook-approved') Approved — it's live now. @break
                        @case('playbook-rejected') Proposal rejected. @break
                        @case('playbook-mode-changed') Layer mode changed. @break
                        @default Done.
                    @endswitch
                </div>
            @endif

            {{-- how the active version composes (mode is versioned — change it via a proposal) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-1">
                @if ($playbook->activeVersion?->mode === $M::MODE_OVERWRITE)
                    <div class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800">
                        <span class="text-lg leading-none">&#9888;</span>
                        <p><strong>This layer OVERWRITES.</strong> When composed, it discards everything above it
                        (system / team / project) and starts the prompt from this layer's body. Only the layers
                        <em>below</em> it (toward personal) still append.</p>
                    </div>
                @else
                    <p class="text-sm text-gray-600"><strong>Append layer.</strong> Its body is added onto the layers above it.</p>
                @endif
                <p class="text-[11px] text-gray-400">Append vs overwrite is part of a version — change it by proposing a new version below (with its big warning), then approving.</p>
            </div>

            {{-- active version --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Active version</p>
                @if ($playbook->activeVersion)
                    <p class="text-xs text-gray-500">v{{ $playbook->activeVersion->version }} · {{ $playbook->activeVersion->title }}</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-50 rounded-md p-3 max-h-[50vh] overflow-y-auto">{{ $playbook->activeVersion->body }}</pre>
                @else
                    <p class="text-sm text-gray-400 italic">No active version — nothing from this layer composes yet.</p>
                @endif
            </div>

            {{-- pending proposals — decide on each, independent of the diff tool so a
                 v1 proposal (only version, nothing to compare against) is approvable too --}}
            @php $pending = $versions->where('status', $V::STATUS_PROPOSED); @endphp
            @if ($pending->isNotEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">
                        Pending proposal{{ $pending->count() > 1 ? 's' : '' }} ({{ $pending->count() }})
                    </p>
                    @if ($canApprove)
                        <p class="text-xs text-gray-500">Review the body below (use <span class="font-medium">Compare &amp; review</span> for a diff against another version), then decide.</p>
                        @foreach ($pending as $p)
                            @include('settings.partials.proposal-decision', ['version' => $p, 'isPhase' => $isPhase])
                        @endforeach
                    @else
                        <p class="text-sm text-gray-400 italic">Awaiting an approver for this scope.</p>
                    @endif
                </div>
            @endif

            {{-- propose a change (collapsed by default so it's not shown alongside the review) --}}
            @if ($canPropose)
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3"
                     x-data="{ proposing: @js($errors->hasAny(['title', 'summary', 'body', 'note'])) }">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Propose a change</p>
                        <button type="button" @click="proposing = !proposing"
                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                            <span x-text="proposing ? 'Cancel' : 'Propose a change'"></span>
                        </button>
                    </div>
                    <div x-show="proposing" x-cloak class="space-y-3">
                    <p class="text-xs text-gray-500">
                        @if ($canApprove) You can approve this scope, so your change goes live immediately.
                        @else Your change is recorded as a proposal for an approver. @endif
                    </p>
                    <form method="POST" action="{{ route('playbooks.propose') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="{{ $playbook->scope }}">
                        <input type="hidden" name="key" value="{{ $playbook->key }}">
                        @if ($playbook->scope === $M::SCOPE_TEAM)
                            <input type="hidden" name="team_id" value="{{ $playbook->owner_id }}">
                        @elseif ($playbook->scope === $M::SCOPE_PROJECT)
                            <input type="hidden" name="project_id" value="{{ $playbook->owner_id }}">
                        @endif
                        <div>
                            <x-input-label for="p-title" value="Title" />
                            <x-text-input id="p-title" name="title" type="text" class="mt-1 block w-full"
                                          :value="old('title', $playbook->activeVersion?->title ?? $playbook->title)" />
                            <x-input-error :messages="$errors->get('title')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="p-summary" value="Summary (one line — for the main catalog)" />
                            <x-text-input id="p-summary" name="summary" type="text"
                                          class="mt-1 block w-full disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                                          :value="old('summary', $playbook->activeVersion?->summary)"
                                          :disabled="$isPhase" placeholder="What it's for / when to use it" />
                            @if ($isPhase)
                                <p class="text-[11px] text-gray-400 mt-1">Not used for phase playbooks — they compose automatically and aren't listed in the main catalog.</p>
                            @endif
                            <x-input-error :messages="$errors->get('summary')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="p-body" value="Prompt body (markdown)" />
                            <textarea id="p-body" name="body" rows="8"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body', $playbook->activeVersion?->body) }}</textarea>
                            <x-input-error :messages="$errors->get('body')" class="mt-1" />
                        </div>
                        <div x-data="{ mode: '{{ old('mode', $playbook->activeVersion?->mode ?? $M::MODE_APPEND) }}' }">
                            <x-input-label value="How this layer combines" />
                            <x-select name="mode" x-model="mode" class="mt-1 block w-full sm:w-80">
                                <option value="{{ $M::MODE_APPEND }}">Append — add onto the layers above it</option>
                                <option value="{{ $M::MODE_OVERWRITE }}">Overwrite — discard everything above it</option>
                            </x-select>
                            <p x-show="mode === '{{ $M::MODE_OVERWRITE }}'" x-cloak
                               class="mt-2 flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-2 text-xs text-amber-800">
                                <span class="text-base leading-none">&#9888;</span>
                                <span><strong>Overwrite is a full override.</strong> When composed, this layer discards everything above it (system / team / project) and becomes the base.</span>
                            </p>
                        </div>
                        <div>
                            <x-input-label for="p-note" value="Note for the approver (optional)" />
                            <textarea id="p-note" name="note" rows="2"
                                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('note') }}</textarea>
                        </div>
                        <x-primary-button>{{ $canApprove ? 'Publish' : 'Propose' }}</x-primary-button>
                    </form>
                    </div>
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
                            <x-select name="a">
                                @foreach ($versions as $v)
                                    <option value="{{ $v->id }}" @selected($diffA && $diffA->is($v))>v{{ $v->version }} · {{ $v->status }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">To</label>
                            <x-select name="b">
                                @foreach ($versions as $v)
                                    <option value="{{ $v->id }}" @selected($diffB && $diffB->is($v))>v{{ $v->version }} · {{ $v->status }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <x-primary-button>Diff</x-primary-button>
                    </form>

                    @if ($diffA && $diffB && $diffA->isNot($diffB))
                        {{-- field changes (title + summary) are part of the change, shown above the body --}}
                        <div class="text-xs rounded-md border border-gray-100 p-3 space-y-1">
                            <div>
                                <span class="text-gray-400 inline-block w-16">Title</span>
                                @if ($diffA->title !== $diffB->title)
                                    <span class="bg-red-50 text-red-800 px-1 line-through">{{ $diffA->title }}</span>
                                    <span class="text-gray-400">&rarr;</span>
                                    <span class="bg-green-50 text-green-800 px-1">{{ $diffB->title }}</span>
                                @else
                                    <span class="text-gray-600">{{ $diffB->title }}</span>
                                @endif
                            </div>
                            <div>
                                <span class="text-gray-400 inline-block w-16">Summary</span>
                                @if (($diffA->summary ?? '') !== ($diffB->summary ?? ''))
                                    <span class="bg-red-50 text-red-800 px-1 line-through">{{ $diffA->summary ?: '—' }}</span>
                                    <span class="text-gray-400">&rarr;</span>
                                    <span class="bg-green-50 text-green-800 px-1">{{ $diffB->summary ?: '—' }}</span>
                                @else
                                    <span class="text-gray-600">{{ $diffB->summary ?: '—' }}@if ($isPhase) <span class="text-gray-300">(n/a for phase playbooks)</span>@endif</span>
                                @endif
                            </div>
                            <div>
                                <span class="text-gray-400 inline-block w-16">Mode</span>
                                @if ($diffA->mode !== $diffB->mode)
                                    <span class="bg-red-50 text-red-800 px-1 line-through">{{ $diffA->mode }}</span>
                                    <span class="text-gray-400">&rarr;</span>
                                    <span class="bg-green-50 text-green-800 px-1">{{ $diffB->mode }}</span>
                                    @if ($diffB->mode === $M::MODE_OVERWRITE)<span class="text-amber-600">&#9888; full override</span>@endif
                                @else
                                    <span class="text-gray-600">{{ $diffB->mode }}</span>
                                @endif
                            </div>
                        </div>
                        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Body</p>
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

                    {{-- decisions live in the "Pending proposals" block above (works for v1 proposals too) --}}
                    @if ($canApprove && $diffB && $diffB->isProposed())
                        <p class="border-t border-gray-100 pt-3 text-xs text-gray-500">Approve, reject or edit <span class="font-medium">v{{ $diffB->version }}</span> in <span class="font-medium">Pending proposals</span> above.</p>
                    @endif
                @endif
            </div>

            {{-- version history (read-only log) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-2">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Version history</p>
                <p class="text-xs text-gray-400">Decide on proposals in <span class="font-medium">Pending proposals</span> above; use <span class="font-medium">Compare &amp; review</span> to diff any two versions.</p>
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
