@php
    /** @var \App\Models\Task $task */
    $isPlanReviewer = $task->plan_reviewer_id === auth()->id();
    $sections = $task->planReviewSections;
@endphp

{{-- The structured plan-review walkthrough — the plan mirror of the code-review
     flow. Shown while the card sits at the plan_review gate. --}}
<div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4"
     x-data="planWalkthrough({{ $sections->where('status', 'signed_off')->count() }}, {{ $sections->count() }}, @js($task->planDecisionSummary()))"
     x-on:plan-signed-changed.window="signedCount = $event.detail"
     x-on:plan-decisions-changed.window="decisions = $event.detail">

    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <p class="text-[11px] font-medium text-indigo-500 uppercase tracking-wide">Plan review</p>
            <p class="text-xs text-gray-500">Walk the plan section by section, decide each, then apply the outcome.</p>
        </div>

        {{-- reviewer chip + claim/release --}}
        <div class="flex items-center gap-2">
            @if ($task->planReviewer)
                <span class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2.5 py-1 {{ $isPlanReviewer ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">
                    <span class="size-1.5 rounded-full {{ $isPlanReviewer ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    Reviewing: {{ $task->planReviewer->name }}{{ $isPlanReviewer ? ' (you)' : '' }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2.5 py-1 bg-gray-200 text-gray-600">
                    <span class="size-1.5 rounded-full bg-gray-400"></span>
                    Unassigned
                </span>
            @endif

            @if ($isPlanReviewer)
                <form method="POST" action="{{ route('plan-review.unassign', $task) }}">
                    @csrf
                    <button class="text-xs font-medium rounded-md border border-gray-300 bg-white px-2.5 py-1 text-gray-700 hover:bg-gray-50">Unassign</button>
                </form>
            @elseif (! $task->planReviewer)
                <form method="POST" action="{{ route('plan-review.assign', $task) }}">
                    @csrf
                    <button class="text-xs font-medium rounded-md bg-indigo-600 px-2.5 py-1 text-white hover:bg-indigo-700">Assign to me</button>
                </form>
            @endif
        </div>
    </div>

    @unless ($isPlanReviewer)
        <div class="text-sm bg-amber-50 border border-amber-300 rounded-lg p-3 text-amber-900">
            @if ($task->planReviewer)
                <strong>{{ $task->planReviewer->name }}</strong> is reviewing this plan. Sign-off is locked until they release it — you can still read the walkthrough below.
            @else
                <strong>Assign yourself to review this plan.</strong> The walkthrough is read-only until you do.
            @endif
        </div>
    @endunless

    @if ($sections->isEmpty())
        <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg p-4 text-gray-600">
            The planning agent didn't build a structured walkthrough for this plan. Read the
            <strong>Plan</strong> below and use the lifecycle controls to send it forward or back.
        </div>
    @else
        {{-- progress --}}
        <div class="flex items-center gap-3">
            <div class="flex-1 h-2 rounded-full bg-gray-200 overflow-hidden">
                <div class="h-full bg-indigo-500 transition-all"
                     :style="`width:${ total ? Math.round(100*signedCount/total) : 0 }%`"></div>
            </div>
            <span class="text-sm font-medium text-gray-600" x-text="`${signedCount}/${total} signed off`"></span>
        </div>

        {{-- sections --}}
        <div class="space-y-3">
            @foreach ($sections as $i => $s)
                <section x-data="planSection({{ $s->id }}, {{ $s->status === 'signed_off' ? 'true' : 'false' }}, @js($s->note), @js($s->decision), {{ $i === 0 ? 'true' : 'false' }})"
                         class="rounded-lg border bg-white shadow-sm transition"
                         :class="signedOff ? 'border-emerald-300 opacity-70' : 'border-gray-200'">
                    <header class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="open = !open">
                        <span class="flex-none w-7 h-7 rounded-full grid place-items-center text-sm font-bold"
                              :class="signedOff ? 'bg-emerald-500 text-white' : 'bg-indigo-100 text-indigo-700'"
                              x-text="signedOff ? '✓' : '{{ $i + 1 }}'"></span>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-gray-900">{{ $s->title }}</h3>
                            @if ($s->focus)
                                <p class="text-xs text-gray-400">{{ $s->focus }}</p>
                            @endif
                        </div>
                        <span class="text-gray-300 text-sm" x-text="open ? '▲' : '▼'"></span>
                    </header>

                    <div class="px-4 pb-4" x-show="open" x-collapse>
                        @if ($s->context)
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Where this fits</p>
                            <div class="mb-3"><x-markdown :content="$s->context" /></div>
                        @endif

                        @if (!empty($s->checks))
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-3 mb-1">What to check</p>
                            <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
                                @foreach ($s->checks as $check)
                                    <li>{{ $check }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- per-section decision (distinct from the sign-off below) --}}
                        @if ($isPlanReviewer)
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

                        {{-- comment — autosaves (debounced on input, flushed on blur) --}}
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
        </div>

        {{-- Apply outcome — shown once every section has a decision --}}
        @if ($isPlanReviewer)
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
                        <span>This card moves to <strong>Ready for dev</strong>.</span>
                    </template>
                    <template x-if="decisions.verdict === 'changes_requested'">
                        <span>This card goes back to <strong>Ready for planning</strong> with the compiled plan-rework notes.</span>
                    </template>
                </p>
                <form method="POST" action="{{ route('plan-review.conclude', $task) }}"
                      onsubmit="return confirm('Apply this outcome to the plan? This moves the card.');">
                    @csrf
                    <button class="text-sm font-medium rounded-md px-3 py-2 text-white"
                            :class="decisions.verdict === 'approved' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'">
                        Apply outcome
                    </button>
                </form>
            </div>
        @endif
    @endif

    <script>
        document.addEventListener('alpine:init', () => {
            if (window.__planReviewWired) return;
            window.__planReviewWired = true;

            const csrf = '{{ csrf_token() }}';
            const base = '{{ url('/tasks/'.$task->id.'/plan-review/sections') }}';
            const canSignOff = {{ $isPlanReviewer ? 'true' : 'false' }};

            Alpine.data('planWalkthrough', (signedCount, total, decisions) => ({ signedCount, total, decisions }));

            Alpine.data('planSection', (id, signedOff, note, decision, open) => ({
                id, signedOff, note, decision, open, savedNote: note, canSignOff, justSaved: false,
                async patch(body) {
                    const res = await fetch(`${base}/${this.id}`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (!res.ok) return null;
                    const d = await res.json();
                    this.$dispatch('plan-signed-changed', d.signed_off);
                    this.$dispatch('plan-decisions-changed', d.decisions);
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
        });
    </script>
</div>
