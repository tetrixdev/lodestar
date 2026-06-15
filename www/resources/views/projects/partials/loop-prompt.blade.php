@php
    /** @var \App\Models\Project $project */
    // A /loop command to paste into a fresh Claude Code session (Lodestar MCP
    // connected locally). It self-paces: Claude re-runs it until the backlog is dry.
    $loopPrompt = <<<MD
        /loop Work the Lodestar backlog for project "{$project->name}" (slug: {$project->slug}). Using the Lodestar MCP, load and follow the work skill: call get_skill with key "work" and project "{$project->slug}", then do exactly what it returns — claim each ready task, work it (one subagent per task), report and advance_task. Repeat until nothing is claimable, then stop. Work autonomously; do not ask me questions.
        MD;
@endphp

<div x-data="{ copied: false, prompt: @js($loopPrompt) }" class="inline-flex">
    <button type="button"
            @click="navigator.clipboard.writeText(prompt).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
            class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
            title="Copy a /loop command that works every ready task on this project">
        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.5 7.5 13 10l-4.5 2.5v-5Z" clip-rule="evenodd"/></svg>
        <span x-show="!copied">Copy loop prompt</span>
        <span x-show="copied" x-cloak>Copied!</span>
    </button>
</div>
