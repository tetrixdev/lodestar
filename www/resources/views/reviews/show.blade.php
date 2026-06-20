<x-app-layout>
    @php
        $isAssignee = $review->assigned_to_user_id === auth()->id();
    @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <x-breadcrumb :trail="[
                    ['label' => 'Projects', 'url' => route('projects.index')],
                    ['label' => $review->project->name, 'url' => route('projects.show', $review->project)],
                    ['label' => 'Reviews', 'url' => route('reviews.index', $review->project)],
                    ['label' => $review->title],
                ]" />
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $review->title }}</h2>
                    @foreach ($review->comparisons as $c)
                        <p class="text-xs text-gray-500">
                            @if ($c->repository)
                                <span class="font-medium text-gray-600">{{ $c->repository->full_name }}</span>
                            @endif
                            Comparing
                            <code class="bg-gray-100 px-1 rounded">{{ $c->base_ref ?? '?' }}</code>
                            &hellip;
                            <code class="bg-gray-100 px-1 rounded">{{ $c->head_ref ?? '?' }}</code>
                        </p>
                    @endforeach
                </div>
            </div>

            {{-- assignee chip + claim/release --}}
            <div class="flex items-center gap-2">
                @if ($review->assignee)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2.5 py-1 {{ $isAssignee ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">
                        <span class="size-1.5 rounded-full {{ $isAssignee ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                        Reviewing: {{ $review->assignee->name }}{{ $isAssignee ? ' (you)' : '' }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2.5 py-1 bg-gray-200 text-gray-600">
                        <span class="size-1.5 rounded-full bg-gray-400"></span>
                        Unassigned
                    </span>
                @endif

                @if ($isAssignee)
                    <form method="POST" action="{{ route('reviews.unassign', $review) }}">
                        @csrf
                        <button class="text-xs font-medium rounded-md border border-gray-300 bg-white px-2.5 py-1 text-gray-700 hover:bg-gray-50">Unassign</button>
                    </form>
                @elseif (! $review->assignee)
                    <form method="POST" action="{{ route('reviews.assign', $review) }}">
                        @csrf
                        <button class="text-xs font-medium rounded-md bg-indigo-600 px-2.5 py-1 text-white hover:bg-indigo-700">Assign to me</button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-10"
         x-data="walkthrough({{ $review->sections->where('status', 'signed_off')->count() }}, {{ $review->sections->count() }}, @js($review->decisionSummary()))"
         x-on:signed-changed.window="signedCount = $event.detail"
         x-on:decisions-changed.window="decisions = $event.detail">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="text-sm bg-blue-50 border border-blue-300 rounded-lg p-3 text-blue-800">
                    {{ session('status') }}
                </div>
            @endif

            @unless ($isAssignee)
                <div class="text-sm bg-amber-50 border border-amber-300 rounded-lg p-4 text-amber-900 flex items-center justify-between gap-3">
                    <span>
                        @if ($review->assignee)
                            <strong>{{ $review->assignee->name }}</strong> is reviewing this. Sign-off is locked until they release it — you can still read the walkthrough below.
                        @else
                            <strong>Assign yourself to review this.</strong> The walkthrough is read-only until you do — sign-off and notes are disabled.
                        @endif
                    </span>
                    @unless ($review->assignee)
                        <form method="POST" action="{{ route('reviews.assign', $review) }}" class="shrink-0">
                            @csrf
                            <button class="text-xs font-medium rounded-md bg-indigo-600 px-2.5 py-1 text-white hover:bg-indigo-700">Assign to me</button>
                        </form>
                    @endunless
                </div>
            @endunless

            @if ($review->tasks->isNotEmpty())
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-2">Tasks in this review</p>
                    <ul class="space-y-1.5">
                        @foreach ($review->tasks as $task)
                            <li class="flex items-center justify-between gap-3 text-sm">
                                <a href="{{ route('projects.show', $review->project) }}" class="text-indigo-600 hover:underline truncate">{{ $task->title }}</a>
                                <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-gray-100 text-gray-600">
                                    {{ \App\Models\Task::LABELS[$task->status] ?? $task->status }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($review->intro)
                <div class="text-sm bg-amber-50 border border-amber-300 rounded-lg p-4 text-amber-800">
                    {{ $review->intro }}
                </div>
            @endif

            {{-- changed-file coverage tree (GitHub order); every file must land in a section --}}
            @if ($review->files->isNotEmpty())
                @include('reviews.partials.file-tree', ['review' => $review])
            @endif

            {{-- progress --}}
            <div class="sticky top-0 z-10 bg-gray-100/95 backdrop-blur py-3 -mx-1 px-1 rounded">
                <div class="flex items-center gap-3">
                    <div class="flex-1 h-2 rounded-full bg-gray-300 overflow-hidden">
                        <div class="h-full bg-indigo-500 transition-all"
                             :style="`width:${ total ? Math.round(100*signedCount/total) : 0 }%`"></div>
                    </div>
                    <span class="text-sm font-medium text-gray-600" x-text="`${signedCount}/${total} signed off`"></span>
                </div>
            </div>

            @php
                $severityBadge = [
                    'info' => 'bg-gray-100 text-gray-600',
                    'minor' => 'bg-sky-100 text-sky-700',
                    'major' => 'bg-amber-100 text-amber-800',
                    'critical' => 'bg-rose-100 text-rose-700',
                ];
            @endphp

            {{-- A path → fully-loaded ReviewFile map, so section file references and
                 a section link that names a changed file can open the same viewer
                 modal (sections eager-load files as id+path only). --}}
            @php
                $filesByPath = $review->files->keyBy('path');
            @endphp

            {{-- sections --}}
            @foreach ($review->sections as $i => $s)
                <section x-data="section({{ $s->id }}, {{ $s->status === 'signed_off' ? 'true' : 'false' }}, @js($s->note), @js($s->decision), {{ $i === 0 ? 'true' : 'false' }})"
                         class="rounded-lg border bg-white shadow-sm transition"
                         :class="signedOff ? 'border-emerald-300 opacity-70' : 'border-gray-200'">
                    <header class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="open = !open">
                        <span class="flex-none w-7 h-7 rounded-full grid place-items-center text-sm font-bold"
                              :class="signedOff ? 'bg-emerald-500 text-white' : 'bg-indigo-100 text-indigo-700'"
                              x-text="signedOff ? '✓' : '{{ $i + 1 }}'"></span>
                        <div class="flex-1">
                            <h3 class="font-semibold text-gray-900">{{ $s->title }}</h3>
                            <p class="text-xs text-gray-400">{{ str_replace('_', ' ', $s->mode) }}</p>
                        </div>
                        <span class="text-gray-300 text-sm" x-text="open ? '▲' : '▼'"></span>
                    </header>

                    <div class="px-4 pb-4" x-show="open" x-collapse>
                        @if ($s->context)
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Where this fits</p>
                            <p class="text-sm text-gray-600 mb-3">{{ $s->context }}</p>
                        @endif
                        @if ($s->link)
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Open</p>
                            @php $linkFile = $filesByPath->get($s->link); @endphp
                            @if ($linkFile)
                                {{-- The link names a changed file → open the viewer modal. --}}
                                <x-open-file :file="$linkFile" class="inline-flex text-sm text-indigo-600 hover:underline break-all">{{ $s->link }}</x-open-file>
                            @else
                                <a href="{{ $s->link }}" class="text-sm text-indigo-600 hover:underline break-all">{{ $s->link }}</a>
                            @endif
                        @endif

                        {{-- The changed files this section covers — each opens the viewer.
                             Collapsed by default; the header matches the "Changed files"
                             tree disclosure in file-tree.blade.php. --}}
                        @if ($s->files->isNotEmpty())
                            <div class="mt-3" x-data="{ open: false }">
                                <button type="button" @click="open = !open"
                                        class="flex items-center gap-1.5 text-[11px] font-medium text-gray-400 uppercase tracking-wide hover:text-gray-600">
                                    <svg class="size-3 text-gray-400 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                                    <span>Files in this section ({{ $s->files->count() }})</span>
                                </button>
                                <ul x-show="open" x-collapse x-cloak class="mt-1 divide-y divide-gray-50 rounded-md border border-gray-100 font-mono text-xs">
                                    @foreach ($s->files as $sf)
                                        @php $sfFull = $filesByPath->get($sf->path) ?? $sf; @endphp
                                        <x-open-file :file="$sfFull" class="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50">
                                            <span class="flex-1 truncate text-gray-700" title="{{ $sf->path }}">{{ $sf->path }}</span>
                                            @if ($sfFull->additions || $sfFull->deletions)
                                                <span class="shrink-0 text-[10px] font-medium tabular-nums">
                                                    <span class="text-emerald-600">+{{ $sfFull->additions }}</span>
                                                    <span class="text-red-600">−{{ $sfFull->deletions }}</span>
                                                </span>
                                            @endif
                                        </x-open-file>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if (!empty($s->checks))
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-3 mb-1">What to check</p>
                            <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
                                @foreach ($s->checks as $check)
                                    <li>{{ $check }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- AI-raised findings (scenario + impact); humans triage each --}}
                        @if ($s->findings->isNotEmpty())
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-4 mb-1">Findings</p>
                            <ul class="space-y-2">
                                @foreach ($s->findings as $f)
                                    <li x-data="finding({{ $s->id }}, {{ $f->id }}, @js($f->status))"
                                        class="rounded-md border p-3"
                                        :class="{
                                            'border-emerald-200 bg-emerald-50/40': status === 'approved',
                                            'border-gray-200 bg-gray-50 opacity-60': status === 'dismissed',
                                            'border-rose-200 bg-rose-50/40': status === 'must_fix',
                                            'border-gray-200': status === 'open',
                                        }">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 {{ $severityBadge[$f->severity] ?? 'bg-gray-100 text-gray-600' }}">{{ $f->severity }}</span>
                                                <span class="text-sm font-medium text-gray-900">{{ $f->title }}</span>
                                            </div>
                                            <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-gray-400" x-text="status.replace('_', ' ')"></span>
                                        </div>
                                        @if ($f->detail)
                                            <div class="mt-1.5"><x-markdown :content="$f->detail" /></div>
                                        @endif
                                        @if ($isAssignee)
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                <button @click="triage('approved')" :disabled="busy"
                                                        class="text-[11px] font-medium rounded px-2 py-1 border"
                                                        :class="status === 'approved' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-700 border-emerald-300 hover:bg-emerald-50'">Approve</button>
                                                <button @click="triage('must_fix')" :disabled="busy"
                                                        class="text-[11px] font-medium rounded px-2 py-1 border"
                                                        :class="status === 'must_fix' ? 'bg-rose-500 text-white border-rose-500' : 'bg-white text-rose-700 border-rose-300 hover:bg-rose-50'">Must fix</button>
                                                <button @click="triage('dismissed')" :disabled="busy"
                                                        class="text-[11px] font-medium rounded px-2 py-1 border"
                                                        :class="status === 'dismissed' ? 'bg-gray-500 text-white border-gray-500' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'">Dismiss</button>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- per-section decision (distinct from the sign-off below) --}}
                        @if ($isAssignee)
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-4 mb-1">Decision</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button @click="setDecision('approved')"
                                        class="text-xs font-medium rounded-md px-2.5 py-1 border"
                                        :class="decision === 'approved' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-emerald-700 border-emerald-300 hover:bg-emerald-50'">Approve</button>
                                <button @click="setDecision('changes_requested')"
                                        class="text-xs font-medium rounded-md px-2.5 py-1 border"
                                        :class="decision === 'changes_requested' ? 'bg-rose-500 text-white border-rose-500' : 'bg-white text-rose-700 border-rose-300 hover:bg-rose-50'">Request changes</button>
                            </div>
                        @elseif ($s->decision)
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-4 mb-1">Decision</p>
                            <span class="text-xs font-medium rounded-md px-2.5 py-1 {{ $s->decision === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ str_replace('_', ' ', $s->decision) }}</span>
                        @endif

                        {{-- Comment is always available (a note reads well alongside an
                             approval too) and autosaves — debounced on input, flushed on
                             blur. No manual save button. --}}
                        <textarea x-model="note" rows="2" placeholder="Your comment / change request for this section…"
                                  :disabled="!canSignOff"
                                  @input.debounce.600ms="saveNote()" @blur="saveNote()"
                                  class="mt-3 w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed"></textarea>

                        <div class="flex items-center justify-between mt-2">
                            <label class="flex items-center gap-2 text-sm select-none" :class="canSignOff ? '' : 'text-gray-400'">
                                <input type="checkbox" x-model="signedOff" @change="saveStatus()" :disabled="!canSignOff"
                                       class="rounded text-emerald-500 focus:ring-emerald-500 disabled:cursor-not-allowed">
                                <span>I've reviewed this section</span>
                            </label>
                            <span x-show="justSaved" x-cloak x-transition.opacity.duration.500ms
                                  class="text-xs text-gray-400">Saved</span>
                        </div>
                    </div>
                </section>
            @endforeach

            <div x-show="total > 0 && signedCount >= total" x-cloak x-transition
                 class="rounded-lg bg-emerald-500 text-white p-5 text-center font-semibold">
                All sections signed off — ready to merge. 🎉
            </div>

            {{-- Apply outcome — shown once every section has a decision; drives the linked task(s) --}}
            @if ($isAssignee && $review->outcome === null)
                <div x-show="decisions.all_decided" x-cloak x-transition
                     class="rounded-lg border bg-white shadow-sm p-5 space-y-3"
                     :class="decisions.verdict === 'approved' ? 'border-emerald-300' : 'border-rose-300'">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold text-gray-900">Apply outcome</h3>
                        <span class="text-xs font-semibold uppercase tracking-wide rounded px-2 py-0.5"
                              :class="decisions.verdict === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                              x-text="decisions.verdict === 'approved' ? 'Approve' : 'Request changes'"></span>
                    </div>
                    <p class="text-sm text-gray-600">
                        <span x-text="decisions.approved"></span> approved,
                        <span x-text="decisions.changes_requested"></span> requesting changes.
                        <template x-if="decisions.verdict === 'approved'">
                            <span>Linked tasks will move to <strong>Approved</strong>.</span>
                        </template>
                        <template x-if="decisions.verdict === 'changes_requested'">
                            <span>Linked tasks will go back to <strong>Ready for dev</strong> with the compiled rework notes.</span>
                        </template>
                    </p>
                    <form method="POST" action="{{ route('reviews.conclude', $review) }}"
                          onsubmit="return confirm('Apply this outcome to the linked task(s)? This cannot be undone here.');">
                        @csrf
                        <button class="text-sm font-medium rounded-md px-3 py-2 text-white"
                                :class="decisions.verdict === 'approved' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'">
                            Apply outcome
                        </button>
                    </form>
                </div>
            @elseif ($review->outcome !== null)
                <div class="rounded-lg p-5 text-center font-semibold {{ $review->outcome === 'approved' ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white' }}">
                    Outcome applied: {{ str_replace('_', ' ', $review->outcome) }}.
                </div>
            @endif

        </div>

        {{-- Changed-file viewer modal: opened by the file tree's `open-file` event;
             fetches a server-rendered fragment per mode and injects it. --}}
        <div x-data="fileModal()" x-on:open-file.window="open($event.detail)"
             x-on:keydown.escape.window="close()">
            <div x-show="shown" x-cloak class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8"
                 x-transition.opacity>
                <div class="absolute inset-0 bg-gray-900/40" @click="close()"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[85vh] flex flex-col">
                    <header class="flex items-center gap-3 px-4 py-3 border-b border-gray-200">
                        <span class="shrink-0 w-16 text-[10px] uppercase tracking-wide rounded px-1 py-0.5 text-center"
                              :class="{
                                  'text-emerald-700 bg-emerald-50': file.status === 'added',
                                  'text-amber-700 bg-amber-50': file.status === 'modified',
                                  'text-red-700 bg-red-50': file.status === 'removed',
                                  'text-violet-700 bg-violet-50': file.status === 'renamed',
                              }" x-text="file.status"></span>
                        <span class="flex-1 min-w-0 flex items-center gap-1.5">
                            <template x-if="file.status === 'renamed' && file.oldPath">
                                <span class="shrink min-w-0 truncate text-xs text-gray-400" :title="file.oldPath">
                                    <code x-text="file.oldPath"></code>
                                    <span class="px-0.5">→</span>
                                </span>
                            </template>
                            <code class="shrink-0 max-w-full truncate text-sm text-gray-800" :title="file.path" x-text="file.path"></code>
                        </span>
                        <span class="shrink-0 text-xs font-medium tabular-nums">
                            <span class="text-emerald-600" x-text="`+${file.additions}`"></span>
                            <span class="text-red-600" x-text="`−${file.deletions}`"></span>
                        </span>
                        <button @click="close()" class="shrink-0 text-gray-400 hover:text-gray-700 text-lg leading-none">&times;</button>
                    </header>

                    <div class="flex items-center gap-1 px-4 py-2 border-b border-gray-100">
                        <template x-for="m in modes" :key="m.key">
                            <button @click="setMode(m.key)"
                                    class="text-xs font-medium rounded-md px-2.5 py-1 border"
                                    :class="mode === m.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                                    x-text="m.label"></button>
                        </template>
                    </div>

                    <div class="overflow-auto flex-1">
                        <div x-show="loading" class="p-6 text-center text-sm text-gray-400">Loading…</div>
                        <div x-show="!loading" x-html="body"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            const csrf = '{{ csrf_token() }}';
            const base = '{{ url('/reviews/'.$review->id.'/sections') }}';
            const canSignOff = {{ $isAssignee ? 'true' : 'false' }};

            Alpine.data('walkthrough', (signedCount, total, decisions) => ({ signedCount, total, decisions }));

            const filesBase = '{{ url('/reviews/'.$review->id.'/files') }}';
            Alpine.data('fileModal', () => ({
                shown: false,
                loading: false,
                body: '',
                mode: 'diff',
                file: { id: null, path: '', oldPath: null, status: '', additions: 0, deletions: 0, markdown: false },
                // Markdown files lead with the rendered rich diff; code files lead
                // with the raw unified patch.
                get defaultMode() { return this.file.markdown ? 'rich' : 'diff'; },
                get modes() {
                    if (this.file.markdown) {
                        // Diff = rich rendered diff; Preview = clean rendered head;
                        // Source = the raw unified patch.
                        return [
                            { key: 'rich', label: 'Diff' },
                            { key: 'preview', label: 'Preview' },
                            { key: 'diff', label: 'Source' },
                        ];
                    }
                    return [
                        { key: 'diff', label: 'Diff' },
                        { key: 'full', label: 'Full file' },
                    ];
                },
                open(detail) {
                    this.file = detail;
                    this.mode = this.defaultMode;
                    this.shown = true;
                    this.load();
                },
                close() { this.shown = false; this.body = ''; },
                setMode(mode) {
                    if (mode === this.mode) return;
                    this.mode = mode;
                    this.load();
                },
                async load() {
                    if (!this.file.id) return;
                    this.loading = true;
                    this.body = '';
                    try {
                        const res = await fetch(`${filesBase}/${this.file.id}?mode=${this.mode}`, {
                            headers: { 'Accept': 'text/html' },
                        });
                        this.body = res.ok ? await res.text() : '<div class="p-6 text-center text-sm text-red-500">Could not load this file.</div>';
                    } catch (e) {
                        this.body = '<div class="p-6 text-center text-sm text-red-500">Could not load this file.</div>';
                    } finally {
                        this.loading = false;
                        // The fetched fragment (preview / rich diff) may contain mermaid
                        // blocks injected via x-html — render them once the DOM updates.
                        this.$nextTick(() => window.renderMermaid?.(this.$el));
                    }
                },
            }));

            Alpine.data('section', (id, signedOff, note, decision, open) => ({
                // Everything autosaves immediately via patch(): the reviewed-checkbox
                // (status open↔signed_off), the Approve/Request-changes decision, and
                // the comment (debounced on input + flushed on blur). No manual save.
                id, signedOff, note, decision, open, savedNote: note, canSignOff, justSaved: false,
                async patch(body) {
                    const res = await fetch(`${base}/${this.id}`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (!res.ok) return null;
                    const d = await res.json();
                    this.$dispatch('signed-changed', d.signed_off);
                    this.$dispatch('decisions-changed', d.decisions);
                    this.flashSaved();
                    return d;
                },
                flashSaved() {
                    this.justSaved = true;
                    clearTimeout(this._savedTimer);
                    this._savedTimer = setTimeout(() => { this.justSaved = false; }, 1500);
                },
                async saveStatus() {
                    if (!this.canSignOff) return;
                    await this.patch({ status: this.signedOff ? 'signed_off' : 'open' });
                },
                async saveNote() {
                    if (!this.canSignOff || this.note === this.savedNote) return;
                    const d = await this.patch({ note: this.note });
                    if (d) this.savedNote = this.note;
                },
                async setDecision(value) {
                    if (!this.canSignOff) return;
                    const next = this.decision === value ? null : value;
                    const d = await this.patch({ decision: next });
                    if (d) this.decision = next;
                },
            }));

            Alpine.data('finding', (sectionId, id, status) => ({
                sectionId, id, status, busy: false,
                async triage(value) {
                    if (!canSignOff) return;
                    this.busy = true;
                    try {
                        const res = await fetch(`${base}/${this.sectionId}/findings/${this.id}`, {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ status: value }),
                        });
                        if (res.ok) this.status = value;
                    } finally {
                        this.busy = false;
                    }
                },
            }));
        });
    </script>
</x-app-layout>
