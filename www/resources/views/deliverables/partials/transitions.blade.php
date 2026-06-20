@php
    /** @var \App\Models\Deliverable $deliverable */
    /** @var array $targets legal target statuses, in forward·back·cancel order */
    $T = \App\Models\Task::class;        // shared KIND vocabulary
    $D = \App\Models\Deliverable::class;

    $forward = null; $back = null; $cancel = null;
    foreach ($targets as $t) {
        $kind = $deliverable->transitionKind($t);
        if ($kind === $T::KIND_FORWARD) { $forward = $t; }
        elseif ($kind === $T::KIND_BACK) { $back = $t; }
        elseif ($kind === $T::KIND_CANCEL) { $cancel = $t; }
    }
@endphp

@if ($back)
    <form method="POST" action="{{ route('deliverables.move', $deliverable) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $back }}">
        <button class="text-gray-400 hover:text-gray-700 text-xs px-1"
                title="Back to {{ $D::LABELS[$back] }}">&larr; {{ $D::LABELS[$back] }}</button>
    </form>
@endif

@if ($forward)
    <form method="POST" action="{{ route('deliverables.move', $deliverable) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $forward }}">
        <button class="inline-flex items-center gap-1 rounded text-xs px-1.5 py-0.5 font-medium text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50"
                title="Advance to {{ $D::LABELS[$forward] }}">
            {{ $D::LABELS[$forward] }} &rarr;
        </button>
    </form>
@endif

@if ($cancel)
    <form method="POST" action="{{ route('deliverables.move', $deliverable) }}" class="ml-auto">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $cancel }}">
        <button class="text-gray-300 hover:text-red-500 text-xs px-1" title="Archive (cancel)">&times;</button>
    </form>
@endif
