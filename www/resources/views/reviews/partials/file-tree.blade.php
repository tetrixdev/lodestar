@php
    /** @var \App\Models\Review $review */
    // Map each changed-file path -> the 1-based section numbers covering it.
    $coverMap = [];
    foreach ($review->sections as $idx => $s) {
        foreach ($s->files as $f) {
            $coverMap[$f->path][] = $idx + 1;
        }
    }
    $cov = $review->coverage();
    $statusColor = [
        'added' => 'text-emerald-700 bg-emerald-50',
        'modified' => 'text-amber-700 bg-amber-50',
        'removed' => 'text-red-700 bg-red-50',
        'renamed' => 'text-violet-700 bg-violet-50',
    ];
@endphp

<div class="bg-white border border-gray-200 rounded-lg" x-data="{ open: {{ $cov['complete'] ? 'false' : 'true' }} }">
    <header class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="open = !open">
        <svg class="size-4 text-gray-400 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
        <span class="text-sm font-medium text-gray-800">Changed files</span>
        <span class="text-xs text-gray-500">{{ $cov['total'] }} file{{ $cov['total'] === 1 ? '' : 's' }}</span>
        @if ($cov['complete'])
            <span class="ml-auto inline-flex items-center gap-1 text-xs font-medium rounded-full px-2 py-0.5 bg-emerald-100 text-emerald-700">
                ✓ all covered
            </span>
        @else
            <span class="ml-auto inline-flex items-center gap-1 text-xs font-medium rounded-full px-2 py-0.5 bg-red-100 text-red-700">
                {{ count($cov['uncovered']) }} uncovered
            </span>
        @endif
    </header>

    <div x-show="open" x-collapse x-cloak class="border-t border-gray-100">
        <ul class="divide-y divide-gray-50 font-mono text-xs">
            @foreach ($review->files as $f)
                @php $covering = $coverMap[$f->path] ?? []; @endphp
                <li>
                    <x-open-file :file="$f"
                        class="flex items-center gap-2 px-4 py-1.5 hover:bg-gray-50 {{ $covering ? '' : 'bg-red-50/60' }}">
                        <span class="shrink-0 w-16 text-[10px] uppercase tracking-wide rounded px-1 py-0.5 text-center {{ $statusColor[$f->status] ?? 'text-gray-600 bg-gray-100' }}">{{ $f->status }}</span>
                        <span class="flex-1 truncate text-gray-700" title="{{ $f->path }}">{{ $f->path }}</span>
                        @if ($f->additions || $f->deletions)
                            <span class="shrink-0 text-[10px] font-medium tabular-nums">
                                <span class="text-emerald-600">+{{ $f->additions }}</span>
                                <span class="text-red-600">−{{ $f->deletions }}</span>
                            </span>
                        @endif
                        @if ($covering)
                            <span class="shrink-0 flex items-center gap-1">
                                @foreach ($covering as $n)
                                    <span class="inline-block text-[10px] font-medium rounded px-1.5 py-0.5 bg-indigo-100 text-indigo-700" title="covered by section {{ $n }}">§{{ $n }}</span>
                                @endforeach
                            </span>
                        @else
                            <span class="shrink-0 text-[10px] font-medium text-red-600">uncovered</span>
                        @endif
                    </x-open-file>
                </li>
            @endforeach
        </ul>
    </div>
</div>
