@props([
    'title',          // heading shown in the modal, e.g. "Plan"
    'summary' => null, // the scannable TL;DR (markdown)
    'full' => null,    // the long-form detail (markdown)
    'empty' => null,   // optional placeholder when there is neither summary nor full
])

@php
    $summary = filled($summary) ? trim((string) $summary) : null;
    $full = filled($full) ? trim((string) $full) : null;
    // Only worth a modal when there's full detail beyond what the summary already shows.
    $hasMore = $full !== null && $full !== $summary;
@endphp

<div x-data="{ open: false }">
    @if ($summary)
        <x-markdown :content="$summary" />
    @elseif ($full)
        <x-markdown :content="$full" />
    @elseif ($empty)
        <p class="text-sm text-gray-400 italic">{{ $empty }}</p>
    @endif

    @if ($summary && $hasMore)
        <button type="button" @click="open = true"
                class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800">
            Show full
            <span aria-hidden="true">&rarr;</span>
        </button>
    @endif

    @if ($hasMore)
        {{-- full-detail reader --}}
        <div x-show="open" x-cloak
             @keydown.escape.window="open = false"
             class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:py-12">
            <div class="fixed inset-0 bg-gray-500/75" @click="open = false"></div>
            <div class="relative mx-auto w-full max-w-4xl rounded-lg bg-white shadow-xl"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">{{ $title }}</h3>
                    <button type="button" @click="open = false"
                            class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
                </div>
                <div class="max-h-[75vh] overflow-y-auto px-5 py-4">
                    <x-markdown :content="$full" />
                </div>
            </div>
        </div>
    @endif
</div>
