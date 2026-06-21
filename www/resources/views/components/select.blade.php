{{-- Compact native select, sized to sit flush with <x-button size="sm"> (py-1.5 / text-sm).
     Pass name / x-model / onchange etc. as normal; options go in the slot. --}}
<select {{ $attributes->merge(['class' => 'rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}>
    {{ $slot }}
</select>
