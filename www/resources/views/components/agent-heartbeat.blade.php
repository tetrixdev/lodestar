@php
    $snap = auth()->check()
        ? \App\Models\Task::agentSnapshot(auth()->user())
        : ['working' => 0, 'tasks' => collect(), 'last_claim' => null];

    $tasks = $snap['tasks'] ?? collect();
    $recentClaim = $snap['last_claim'] && \Illuminate\Support\Carbon::parse($snap['last_claim'])->gt(now()->subMinutes(10));
    $active = $snap['working'] > 0 || $recentClaim;

    // A loop worker stamps claimed_by "loop*"; distinct agents drive the count.
    $agents = $tasks->pluck('claimed_by')->filter()->unique();
    $hasLoop = $agents->contains(fn ($id) => \Illuminate\Support\Str::startsWith((string) $id, 'loop'));
    $label = $snap['working'] > 0
        ? ($hasLoop ? 'Loop running' : 'Agent working').($agents->count() > 1 ? ' ('.$agents->count().')' : '')
        : ($active ? 'Agent active' : 'No agent');

    // Hover: which agent is on which project.
    $hover = $tasks->isNotEmpty()
        ? $tasks->map(fn ($t) => ($t->claimed_by ?: 'agent').' → '.($t->project->name ?? 'project').' (#'.$t->id.')')->unique()->implode("\n")
        : 'No agent activity in the last 10 minutes';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs font-medium '.($active ? 'text-emerald-700' : 'text-gray-400')]) }}
      title="{{ $hover }}">
    @if ($active)
        <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
        </span>
    @else
        <span class="inline-flex rounded-full h-2 w-2 bg-gray-300"></span>
    @endif
    {{ $label }}
</span>
