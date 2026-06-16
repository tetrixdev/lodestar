@php
    /** @var \App\Models\Task $task */
    $T = \App\Models\Task::class;

    // The phase this card's work belongs to: a ready_* card maps through the
    // claim, a *-ing card is already in its working state.
    $workingStatus = $T::CLAIM_MAP[$task->status] ?? $task->status;
    $phase = $T::phaseFor($workingStatus);
    $phaseSuffix = $phase ? " (phase: {$phase})" : '';

    // The markdown to paste into a fresh Claude Code session (Lodestar MCP connected).
    $prompt = <<<MD
        Work Lodestar task #{$task->id}: "{$task->title}"{$phaseSuffix}.

        Using the Lodestar MCP:
        1. claim_task with task_id {$task->id} to take it (skip if it's already in progress).
        2. get_playbook with task_id {$task->id} and follow the returned playbook exactly.
        3. report progress and advance_task as the playbook directs.
        MD;
@endphp

<div x-data="{ copied: false, prompt: @js($prompt) }" class="inline-flex">
    <button type="button"
            @click="navigator.clipboard.writeText(prompt).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
            class="inline-flex items-center gap-1 text-[11px] font-medium text-gray-500 hover:text-gray-800"
            title="Copy a prompt to run this task in a clear Claude Code session">
        <svg class="size-3" viewBox="0 0 20 20" fill="currentColor"><path d="M7 3.5A1.5 1.5 0 0 1 8.5 2h3.879a1.5 1.5 0 0 1 1.06.44l3.122 3.12A1.5 1.5 0 0 1 17 6.622V12.5a1.5 1.5 0 0 1-1.5 1.5h-1v-3.379a3 3 0 0 0-.879-2.121L10.5 5.379A3 3 0 0 0 8.379 4.5H7v-1Z"/><path d="M4.5 6A1.5 1.5 0 0 0 3 7.5v9A1.5 1.5 0 0 0 4.5 18h7a1.5 1.5 0 0 0 1.5-1.5v-5.879a1.5 1.5 0 0 0-.44-1.06L9.44 6.439A1.5 1.5 0 0 0 8.378 6H4.5Z"/></svg>
        <span x-show="!copied">Copy prompt</span>
        <span x-show="copied" x-cloak class="text-emerald-600">Copied!</span>
    </button>
</div>
