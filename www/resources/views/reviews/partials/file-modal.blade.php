@php
    /**
     * Body fragment for the changed-file modal, fetched per-mode.
     *
     * @var string $mode  diff|full|preview
     * @var \App\Models\ReviewFile $file
     * @var ?string $githubUrl    blob link for the fallback
     * @var ?array  $rows         diff/full rows (DiffRenderer output)
     * @var ?string $previewContent  markdown source for preview mode
     * @var ?string $fallback    why we can't render inline (binary/too large), if set
     */
@endphp

@if (! empty($fallback))
    <div class="p-6 text-center text-sm text-gray-500 space-y-3">
        <p>{{ $fallback }}</p>
        @if (! empty($githubUrl))
            <a href="{{ $githubUrl }}" target="_blank" rel="noopener"
               class="inline-block text-xs font-medium rounded-md bg-gray-800 px-3 py-1.5 text-white hover:bg-gray-700">View on GitHub</a>
        @endif
    </div>
@elseif ($mode === 'preview')
    <div class="p-5">
        <x-markdown :content="$previewContent" />
    </div>
@else
    <div class="overflow-x-auto">
        @include('reviews.partials.diff-rows', ['rows' => $rows])
    </div>
@endif
