@php
    /** @var \App\Models\Task $task */
    /** @var array $targets legal target statuses, in forward·back·cancel order */
    $T = \App\Models\Task::class;
    $compact = $compact ?? false;

    // Split the legal targets by kind for clear controls.
    $forward = null; $back = null; $cancel = null;
    foreach ($targets as $t) {
        $kind = $task->transitionKind($t);
        if ($kind === $T::KIND_FORWARD) { $forward = $t; }
        elseif ($kind === $T::KIND_BACK) { $back = $t; }
        elseif ($kind === $T::KIND_CANCEL) { $cancel = $t; }
    }
@endphp

@if ($back)
    <form method="POST" action="{{ route('tasks.update', $task) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $back }}">
        <button class="text-gray-400 hover:text-gray-700 {{ $compact ? 'text-[10px]' : 'text-xs' }} px-1"
                title="Back to {{ $T::LABELS[$back] }}">&larr;</button>
    </form>
@endif

@if ($forward)
    <form method="POST" action="{{ route('tasks.update', $task) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $forward }}">
        <button class="inline-flex items-center gap-1 rounded {{ $compact ? 'text-[10px] px-1' : 'text-xs px-1.5 py-0.5' }} font-medium text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50"
                title="Advance to {{ $T::LABELS[$forward] }}">
            {{ $T::LABELS[$forward] }} &rarr;
        </button>
    </form>
@endif

@if ($cancel)
    <form method="POST" action="{{ route('tasks.update', $task) }}" class="ml-auto">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $cancel }}">
        <button class="text-gray-300 hover:text-red-500 {{ $compact ? 'text-[10px]' : 'text-xs' }} px-1"
                title="Archive (cancel)">&times;</button>
    </form>
@endif
