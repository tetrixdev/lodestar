<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('reviews.index', $review->project) }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $review->title }}</h2>
                @if ($review->base_ref || $review->head_ref)
                    <p class="text-xs text-gray-500">Comparing
                        <code class="bg-gray-100 px-1 rounded">{{ $review->base_ref ?? '?' }}</code>
                        &hellip;
                        <code class="bg-gray-100 px-1 rounded">{{ $review->head_ref ?? '?' }}</code>
                    </p>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-10"
         x-data="walkthrough({{ $review->sections->where('status', 'signed_off')->count() }}, {{ $review->sections->count() }})"
         x-on:signed-changed.window="signedCount = $event.detail">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @if ($review->intro)
                <div class="text-sm bg-amber-50 border border-amber-300 rounded-lg p-4 text-amber-800">
                    {{ $review->intro }}
                </div>
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

            {{-- sections --}}
            @foreach ($review->sections as $i => $s)
                <section x-data="section({{ $s->id }}, {{ $s->status === 'signed_off' ? 'true' : 'false' }}, @js($s->note), {{ $i === 0 ? 'true' : 'false' }})"
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
                            <a href="{{ $s->link }}" class="text-sm text-indigo-600 hover:underline break-all">{{ $s->link }}</a>
                        @endif
                        @if (!empty($s->checks))
                            <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mt-3 mb-1">What to check</p>
                            <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
                                @foreach ($s->checks as $check)
                                    <li>{{ $check }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <textarea x-model="note" rows="2" placeholder="Your comment / change request for this section…"
                                  class="mt-3 w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"></textarea>

                        <div class="flex items-center justify-between mt-2">
                            <label class="flex items-center gap-2 text-sm select-none">
                                <input type="checkbox" x-model="signedOff" @change="save()" class="rounded text-emerald-500 focus:ring-emerald-500">
                                <span>I'm happy with this section</span>
                            </label>
                            <button @click="save()" x-show="dirty" x-cloak
                                    class="text-xs text-indigo-600 hover:underline" x-text="saving ? 'Saving…' : 'Save note'"></button>
                        </div>
                    </div>
                </section>
            @endforeach

            <div x-show="total > 0 && signedCount >= total" x-cloak x-transition
                 class="rounded-lg bg-emerald-500 text-white p-5 text-center font-semibold">
                All sections signed off — ready to merge. 🎉
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            const csrf = '{{ csrf_token() }}';
            const base = '{{ url('/reviews/'.$review->id.'/sections') }}';

            Alpine.data('walkthrough', (signedCount, total) => ({ signedCount, total }));

            Alpine.data('section', (id, signedOff, note, open) => ({
                id, signedOff, note, open, savedNote: note, saving: false,
                get dirty() { return this.note !== this.savedNote; },
                async save() {
                    this.saving = true;
                    try {
                        const res = await fetch(`${base}/${this.id}`, {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ status: this.signedOff ? 'signed_off' : 'open', note: this.note }),
                        });
                        if (res.ok) {
                            const d = await res.json();
                            this.savedNote = this.note;
                            this.$dispatch('signed-changed', d.signed_off);
                        }
                    } finally {
                        this.saving = false;
                    }
                },
            }));
        });
    </script>
</x-app-layout>
