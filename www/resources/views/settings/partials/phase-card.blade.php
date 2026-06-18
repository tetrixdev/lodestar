{{--
  One phase in the context-scoped list: its composing layers (in order, in this
  context) and an on-demand preview of BOTH the rendered composed prompt and its
  raw text. Mobile-first: header wraps, preview toggles are tappable, the prompt
  scrolls inside the card rather than blowing out the page.

  Expects: $phase = ['key','label','layers'(Collection<Playbook>),'composed'=>['body','layers']]
--}}
@php $M = \App\Models\Playbook::class; @endphp
<div class="bg-white shadow-sm rounded-lg p-4 sm:p-5 space-y-3"
     x-data="{ open: false, raw: false }">

    {{-- header: phase name + the scope chain that composes here + actions --}}
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
        <div class="min-w-0">
            <h3 class="font-semibold text-gray-800">{{ $phase['label'] }}</h3>
            <div class="mt-1 flex flex-wrap items-center gap-1">
                @forelse ($phase['composed']['layers'] as $layer)
                    <span class="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-indigo-50 text-indigo-700">
                        {{ $layer['scope'] }}@if ($layer['mode'] === $M::MODE_OVERWRITE)<span class="text-amber-600" title="overwrites the layers above it">&#9888;</span>@endif
                    </span>
                @empty
                    <span class="text-[10px] text-gray-400">— nothing composes here —</span>
                @endforelse
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            @if ($phase['composed']['body'] !== '')
                <button type="button" @click="open = !open"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                    <span x-text="open ? 'Hide preview' : 'Preview prompt'"></span>
                </button>
            @endif
            <button type="button" @click="startProposal('{{ $M::SCOPE_PERSONAL }}', '{{ $phase['key'] }}')"
                    class="text-xs font-medium text-indigo-600 hover:text-indigo-800">+ add a layer</button>
        </div>
    </div>

    {{-- the actual composed prompt — rendered markdown by default, raw text on toggle --}}
    @if ($phase['composed']['body'] !== '')
        <div x-show="open" x-cloak class="space-y-2">
            <div class="flex items-center justify-end">
                <button type="button" @click="raw = !raw"
                        class="text-[11px] font-medium text-gray-500 hover:text-gray-700">
                    <span x-text="raw ? 'Show rendered' : 'Show raw markdown'"></span>
                </button>
            </div>
            <div x-show="!raw" class="rounded-md border border-gray-100 bg-gray-50 p-3 max-h-[60vh] overflow-y-auto">
                <x-markdown :content="$phase['composed']['body']" />
            </div>
            <pre x-show="raw" x-cloak class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-50 rounded-md p-3 max-h-[60vh] overflow-y-auto">{{ $phase['composed']['body'] }}</pre>
        </div>
    @endif

    {{-- the layers themselves (only the ones the user can reach as a slot row) --}}
    @if ($phase['layers']->isNotEmpty())
        <div class="divide-y divide-gray-100 border-t border-gray-100 pt-1">
            @foreach ($phase['layers'] as $slot)
                @include('settings.partials.layer-row', ['slot' => $slot, 'showKey' => false])
            @endforeach
        </div>
    @endif
</div>
