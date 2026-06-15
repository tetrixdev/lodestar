@php
    $snap = auth()->check()
        ? \App\Models\Task::agentSnapshot(auth()->user())
        : ['working' => 0, 'last_claim' => null];

    // Active = something is being worked now, or a claim happened very recently.
    $recentClaim = $snap['last_claim'] && \Illuminate\Support\Carbon::parse($snap['last_claim'])->gt(now()->subMinutes(10));
    $active = $snap['working'] > 0 || $recentClaim;
    $label = $snap['working'] > 0
        ? 'Agent working'.($snap['working'] > 1 ? ' ('.$snap['working'].')' : '')
        : ($active ? 'Agent active' : 'No agent');
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs font-medium '.($active ? 'text-emerald-700' : 'text-gray-400')]) }}
      title="{{ $active ? 'A Lodestar agent is working your backlog' : 'No agent activity in the last 10 minutes' }}">
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
