<x-app-layout>
    @php $M = \App\Models\Skill::class; @endphp

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Skills') }}</h2>
    </x-slot>

    <div class="py-12" x-data="{
        proposeOpen: {{ $errors->hasAny(['scope', 'key', 'title', 'body', 'mode', 'team_id', 'project_id']) ? 'true' : 'false' }},
        scope: '{{ old('scope', $M::SCOPE_PERSONAL) }}',
        mode: '{{ old('mode', $M::MODE_APPEND) }}',
        key: '{{ old('key', '') }}',
        startProposal(scope, key) { this.scope = scope; this.key = key; this.proposeOpen = true; this.$nextTick(() => this.$refs.proposePanel?.scrollIntoView({ behavior: 'smooth' })); },
    }">
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

            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-gray-500">
                    Skills are <strong>layered</strong>: each phase composes system &rarr; team &rarr; project &rarr;
                    personal (personal has the final say).
                </p>
                <button type="button" @click="proposeOpen = ! proposeOpen"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                    <span x-text="proposeOpen ? 'Close' : 'Propose a change / add a layer'"></span>
                </button>
            </div>

            {{-- Propose / add a layer --}}
            <div x-show="proposeOpen" x-cloak x-ref="proposePanel" class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                <h3 class="font-semibold text-gray-800">Propose a change / add a layer</h3>
                <p class="text-xs text-gray-500">
                    Add or change a layer at the personal, team or project scope. If you can approve that scope your
                    change goes live; otherwise it's recorded as a proposal for an approver. (System layers are
                    seeded and can't be edited here — add a layer above them instead.)
                </p>

                <form method="POST" action="{{ route('skills.propose') }}" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label value="Scope" />
                            <select name="scope" x-model="scope" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="{{ $M::SCOPE_PERSONAL }}">Personal (yours)</option>
                                <option value="{{ $M::SCOPE_TEAM }}">Team</option>
                                <option value="{{ $M::SCOPE_PROJECT }}">Project</option>
                            </select>
                            <x-input-error :messages="$errors->get('scope')" class="mt-1" />
                        </div>

                        <div x-show="scope === '{{ $M::SCOPE_TEAM }}'" x-cloak>
                            <x-input-label value="Team" />
                            <select name="team_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                @forelse ($teams as $t)
                                    <option value="{{ $t->id }}" @selected((string) old('team_id') === (string) $t->id)>{{ $t->name }}</option>
                                @empty
                                    <option value="">You're not in a team</option>
                                @endforelse
                            </select>
                            <x-input-error :messages="$errors->get('team_id')" class="mt-1" />
                        </div>

                        <div x-show="scope === '{{ $M::SCOPE_PROJECT }}'" x-cloak>
                            <x-input-label value="Project" />
                            <select name="project_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                @forelse ($projects as $pr)
                                    <option value="{{ $pr->id }}" @selected((string) old('project_id') === (string) $pr->id)>{{ $pr->name }}</option>
                                @empty
                                    <option value="">No projects</option>
                                @endforelse
                            </select>
                            <x-input-error :messages="$errors->get('project_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label value="Phase or named key" />
                            <input list="skill-keys" name="key" x-model="key" type="text"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="develop, or a named key" />
                            <datalist id="skill-keys">
                                @foreach ($phases as $p)
                                    <option value="{{ $p }}">{{ $phaseLabels[$p] ?? $p }}</option>
                                @endforeach
                            </datalist>
                            <x-input-error :messages="$errors->get('key')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="np-title" value="Title" />
                        <x-text-input id="np-title" name="title" type="text" class="mt-1 block w-full" :value="old('title')" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="np-summary" value="Summary (one line — for named skills, shown in the main catalog)" />
                        <x-text-input id="np-summary" name="summary" type="text" class="mt-1 block w-full"
                                      :value="old('summary')" placeholder="What it's for / when to use it" />
                        <x-input-error :messages="$errors->get('summary')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="np-body" value="Prompt body (markdown)" />
                        <textarea id="np-body" name="body" rows="6"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body') }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label value="How this layer combines" />
                        <select name="mode" x-model="mode" class="mt-1 block w-full sm:w-72 rounded-md border-gray-300 text-sm">
                            <option value="{{ $M::MODE_APPEND }}">Append — add onto the layers above it</option>
                            <option value="{{ $M::MODE_OVERWRITE }}">Overwrite — discard everything above it</option>
                        </select>
                        <p x-show="mode === '{{ $M::MODE_OVERWRITE }}'" x-cloak
                           class="mt-2 flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-2 text-xs text-amber-800">
                            <span class="text-base leading-none">&#9888;</span>
                            <span><strong>Overwrite is a full override.</strong> When composed, this layer discards
                            everything above it (system / team / project) and becomes the base — only layers below it
                            still append. Use sparingly.</span>
                        </p>
                        <p class="mt-1 text-[11px] text-gray-400">Mode applies when the layer is first created; an approver can flip it later.</p>
                    </div>

                    <div>
                        <x-input-label for="np-note" value="Note for the approver (optional)" />
                        <textarea id="np-note" name="note" rows="2"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('note') }}</textarea>
                    </div>

                    <x-primary-button>Submit</x-primary-button>
                </form>
            </div>

            {{-- Effective composed prompt per phase --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-800">Effective prompts</h3>
                        <p class="text-xs text-gray-500">
                            How each phase composes
                            @if ($previewProject) on <strong>{{ $previewProject->name }}</strong> (system &rarr; team &rarr; project &rarr; personal).
                            @else for you (system &rarr; personal). Pick a project to preview its team/project layers. @endif
                        </p>
                    </div>
                    <form method="GET" class="flex items-end gap-2 text-sm">
                        {{-- preserve list filters when switching preview --}}
                        @foreach (['scope', 'key', 'team_id', 'project_id', 'status'] as $f)
                            @if ($filters[$f] ?? null)<input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">@endif
                        @endforeach
                        <select name="preview_project" onchange="this.form.submit()" class="rounded-md border-gray-300 text-sm">
                            <option value="">Preview: just me</option>
                            @foreach ($projects as $pr)
                                <option value="{{ $pr->id }}" @selected($previewProject && $previewProject->id === $pr->id)>Preview: {{ $pr->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach ($phases as $phase)
                        @php $c = $composed[$phase]; @endphp
                        <div class="border border-gray-100 rounded-md p-3 space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-gray-700">{{ $phaseLabels[$phase] ?? $phase }}</span>
                                <button type="button" @click="startProposal('{{ $M::SCOPE_PERSONAL }}', '{{ $phase }}')"
                                        class="shrink-0 text-[11px] font-medium text-indigo-600 hover:text-indigo-800">+ personal addition</button>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @forelse ($c['layers'] as $layer)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-50 text-indigo-700">
                                        {{ $layer['scope'] }}@if ($layer['mode'] === $M::MODE_OVERWRITE)<span class="text-amber-600" title="overwrites the layers above it">&#9888;</span>@endif
                                    </span>
                                @empty
                                    <span class="text-[10px] text-gray-400">— none —</span>
                                @endforelse
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
                                @if ($slot->mode === $M::MODE_OVERWRITE)
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
