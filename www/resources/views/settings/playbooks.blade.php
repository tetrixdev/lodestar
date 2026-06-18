<x-app-layout>
    @php $M = \App\Models\Playbook::class; @endphp

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Playbooks') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-10" x-data="{
        proposeOpen: {{ $errors->hasAny(['scope', 'key', 'title', 'summary', 'body', 'mode', 'team_id', 'project_id']) ? 'true' : 'false' }},
        scope: '{{ old('scope', $M::SCOPE_PERSONAL) }}',
        mode: '{{ old('mode', $M::MODE_APPEND) }}',
        key: '{{ old('key', '') }}',
        phases: @js($phaseKeys),
        startProposal(scope, key) { this.scope = scope; this.key = key; this.proposeOpen = true; this.$nextTick(() => this.$refs.proposePanel?.scrollIntoView({ behavior: 'smooth' })); },
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">
                    @switch(session('status'))
                        @case('playbook-published') Change published — it's live now. @break
                        @case('playbook-proposed') Proposed — an approver will review it. @break
                        @case('playbook-approved') Approved — it's live now. @break
                        @case('playbook-rejected') Proposal rejected. @break
                        @case('playbook-mode-changed') Layer mode changed. @break
                        @default Saved.
                    @endswitch
                </div>
            @endif

            {{-- Context bar: ONE picker drives the whole page. Everything below shows
                 the layers + composed prompts as they apply in this context. --}}
            <div class="bg-white shadow-sm rounded-lg p-4 sm:p-5 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="min-w-0">
                        <label for="context" class="block text-[11px] font-medium text-gray-400 uppercase tracking-wide">Showing playbooks for</label>
                        <form method="GET" class="mt-1">
                            <x-select id="context" name="context" onchange="this.form.submit()"
                                      class="block w-full sm:w-72 rounded-md border-gray-300 text-sm">
                                <option value="">Just me (system &rarr; personal)</option>
                                @foreach ($projects as $pr)
                                    <option value="{{ $pr->id }}" @selected($contextProject && $contextProject->id === $pr->id)>Project: {{ $pr->name }}</option>
                                @endforeach
                            </x-select>
                        </form>
                    </div>
                    <button type="button" @click="proposeOpen = ! proposeOpen"
                            class="shrink-0 inline-flex items-center justify-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                        <span x-text="proposeOpen ? 'Close' : 'Propose a change / add a layer'"></span>
                    </button>
                </div>
                <p class="text-xs text-gray-500">
                    Playbooks are <strong>layered</strong>: each phase composes system &rarr; team &rarr; project &rarr; personal (personal has the final say).
                    @if ($contextProject)
                        You're viewing <strong>{{ $contextProject->name }}</strong>'s context — its team &amp; project layers are folded in.
                        @unless ($allowPersonal)<span class="text-amber-700">This project's team blocks personal layers, so yours are dropped here.</span>@endunless
                    @else
                        Pick a project above to fold in its team &amp; project layers.
                    @endif
                </p>
            </div>

            {{-- Propose / add a layer --}}
            <div x-show="proposeOpen" x-cloak x-ref="proposePanel" class="bg-white shadow-sm rounded-lg p-4 sm:p-5 space-y-4">
                <h3 class="font-semibold text-gray-800">Propose a change / add a layer</h3>
                <p class="text-xs text-gray-500">
                    Add or change a layer at the personal, team or project scope. If you can approve that scope your
                    change goes live; otherwise it's recorded as a proposal for an approver. (System layers are
                    seeded and can't be edited here — add a layer above them instead.)
                </p>

                <form method="POST" action="{{ route('playbooks.propose') }}" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label value="Scope" />
                            <x-select name="scope" x-model="scope" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="{{ $M::SCOPE_PERSONAL }}">Personal (yours)</option>
                                <option value="{{ $M::SCOPE_TEAM }}">Team</option>
                                <option value="{{ $M::SCOPE_PROJECT }}">Project</option>
                            </x-select>
                            <x-input-error :messages="$errors->get('scope')" class="mt-1" />
                        </div>

                        <div x-show="scope === '{{ $M::SCOPE_TEAM }}'" x-cloak>
                            <x-input-label value="Team" />
                            <x-select name="team_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                @forelse ($teams as $t)
                                    <option value="{{ $t->id }}" @selected((string) old('team_id') === (string) $t->id)>{{ $t->name }}</option>
                                @empty
                                    <option value="">You're not in a team</option>
                                @endforelse
                            </x-select>
                            <x-input-error :messages="$errors->get('team_id')" class="mt-1" />
                        </div>

                        <div x-show="scope === '{{ $M::SCOPE_PROJECT }}'" x-cloak>
                            <x-input-label value="Project" />
                            <x-select name="project_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                @forelse ($projects as $pr)
                                    <option value="{{ $pr->id }}" @selected((string) old('project_id', $contextProject?->id) === (string) $pr->id)>{{ $pr->name }}</option>
                                @empty
                                    <option value="">No projects</option>
                                @endforelse
                            </x-select>
                            <x-input-error :messages="$errors->get('project_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label value="Phase or named key" />
                            <input list="playbook-keys" name="key" x-model="key" type="text"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="develop, or a named key" />
                            <datalist id="playbook-keys">
                                @foreach ($phaseKeys as $p)
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
                        <x-input-label for="np-summary" value="Summary (one line — required for named playbooks, shown in the main catalog)" />
                        <x-text-input id="np-summary" name="summary" type="text"
                                      class="mt-1 block w-full disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                                      x-bind:disabled="phases.includes(key)"
                                      :value="old('summary')" placeholder="What it's for / when to use it" />
                        <p x-show="phases.includes(key)" x-cloak class="text-[11px] text-gray-400 mt-1">Not used for phase playbooks — they compose automatically and aren't listed in the main catalog.</p>
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
                        <x-select name="mode" x-model="mode" class="mt-1 block w-full sm:w-72 rounded-md border-gray-300 text-sm">
                            <option value="{{ $M::MODE_APPEND }}">Append — add onto the layers above it</option>
                            <option value="{{ $M::MODE_OVERWRITE }}">Overwrite — discard everything above it</option>
                        </x-select>
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

            {{-- The one list: each phase, with the layers composing in this context and
                 a preview of both each layer's body and the whole composed prompt. --}}
            <div class="space-y-4">
                @foreach ($phases as $phase)
                    @include('settings.partials.phase-card', ['phase' => $phase])
                @endforeach
            </div>

            {{-- Named (on-demand) playbooks reachable in this context --}}
            <div class="bg-white shadow-sm rounded-lg p-4 sm:p-5 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-gray-800">Named playbooks</h3>
                        <p class="text-xs text-gray-500">Loaded on demand (not composed) — most-specific scope wins in this context.</p>
                    </div>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($named as $slot)
                        @include('settings.partials.layer-row', ['slot' => $slot, 'showKey' => true])
                    @empty
                        <p class="text-sm text-gray-400 italic py-2">No named playbooks in this context.</p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
