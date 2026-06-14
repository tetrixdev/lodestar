@php /** @var \App\Models\Task $task */ @endphp
{{-- Escape hatch for a stuck working card: return it to its queue, clear the claim. --}}
<form method="POST" action="{{ route('tasks.release', $task) }}"
      onsubmit="return confirm('Release this task back to its queue? The current agent loses the claim.')">
    @csrf
    @method('PATCH')
    <button type="submit"
            class="text-[11px] font-medium text-amber-700 hover:text-amber-900 hover:underline"
            title="Return this task to its queue and clear the claim">
        Release
    </button>
</form>
