@php
    $tray = auth()->check()
        ? \App\Support\AttentionTray::for(auth()->user())
        : ['total' => 0, 'urgent' => 0, 'buckets' => []];

    $total = $tray['total'];
    // Red when something is genuinely time-critical (overdue/due soon), else amber.
    $badgeClass = $tray['urgent'] > 0 ? 'bg-red-500' : 'bg-amber-500';

    $dot = [
        'amber' => 'bg-amber-400',
        'indigo' => 'bg-indigo-400',
        'red' => 'bg-red-500',
    ];
    $countText = [
        'amber' => 'text-amber-700',
        'indigo' => 'text-indigo-700',
        'red' => 'text-red-600',
    ];
@endphp

<x-dropdown align="right" width="w-80" contentClasses="py-0 bg-white">
    <x-slot name="trigger">
        <button class="relative inline-flex items-center justify-center size-9 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-50 focus:outline-none transition"
                title="Waiting on you">
            {{-- bell --}}
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            @if ($total > 0)
                <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-semibold text-white {{ $badgeClass }}">{{ $total > 99 ? '99+' : $total }}</span>
            @endif
        </button>
    </x-slot>

    <x-slot name="content">
        <div class="px-4 py-3 border-b border-gray-100">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Waiting on you</p>
            @if ($total === 0)
                <p class="text-sm text-gray-400 mt-0.5">All clear — nothing waiting.</p>
            @endif
        </div>

        <div class="max-h-[70vh] overflow-y-auto divide-y divide-gray-100">
            @foreach ($tray['buckets'] as $bucket)
                @continue($bucket['count'] === 0)
                <div class="py-2">
                    <a href="{{ $bucket['all_url'] }}"
                       class="flex items-center gap-2 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-600 hover:bg-gray-50">
                        <span class="size-2 rounded-full {{ $dot[$bucket['color']] }}"></span>
                        <span class="flex-1">{{ $bucket['label'] }}</span>
                        <span class="{{ $countText[$bucket['color']] }} normal-case">{{ $bucket['count'] }}</span>
                    </a>
                    @foreach ($bucket['items'] as $item)
                        <a href="{{ $item['url'] }}" class="block px-4 py-1.5 hover:bg-gray-50">
                            <div class="text-sm text-gray-800 truncate">{{ $item['label'] }}</div>
                            @if (!empty($item['sub']))
                                <div class="text-xs text-gray-400 truncate">{{ $item['sub'] }}</div>
                            @endif
                        </a>
                    @endforeach
                    @if ($bucket['count'] > count($bucket['items']))
                        <a href="{{ $bucket['all_url'] }}" class="block px-4 py-1 text-xs text-indigo-600 hover:text-indigo-800">
                            + {{ $bucket['count'] - count($bucket['items']) }} more &rarr;
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </x-slot>
</x-dropdown>
