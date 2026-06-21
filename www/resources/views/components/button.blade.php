@props([
    'variant' => 'primary',
    'size' => 'md',
    'as' => 'button',
])

@php
    $base = 'inline-flex items-center rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-25';

    $variantClasses = match ($variant) {
        'secondary' => 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 focus:ring-indigo-500',
        'danger' => 'bg-red-600 border border-transparent text-white hover:bg-red-500 active:bg-red-700 focus:ring-red-500',
        'accent' => 'bg-emerald-600 border border-transparent text-white hover:bg-emerald-700 focus:ring-emerald-500',
        default => 'bg-gray-800 border border-transparent text-white hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:ring-indigo-500',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-3 py-1.5 text-xs font-medium',
        default => 'px-4 py-2 font-semibold text-xs',
    };

    $classes = trim("$base $variantClasses $sizeClasses");
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
