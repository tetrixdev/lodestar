{{--
  One playbook-layer row — mobile-first: scope + key/owner stack and wrap, the
  version/proposed badges sit on their own line on narrow screens. Links to the
  slot's detail page.

  Expects: $slot (Playbook with activeVersion + proposed_count loaded),
           $showKey (bool, default true — show the key; phase cards already
           group by key so they pass false).
--}}
@php
    $M = \App\Models\Playbook::class;
    $showKey = $showKey ?? true;
@endphp
<a href="{{ route('playbooks.show', $slot) }}"
   class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between sm:gap-3 py-2.5 hover:bg-gray-50 -mx-2 px-2 rounded">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0">
        <span class="text-[11px] font-medium uppercase tracking-wide rounded px-2 py-0.5 bg-gray-100 text-gray-700">{{ $slot->scope }}</span>
        @if ($showKey)
            <span class="font-medium text-gray-800 truncate">{{ $slot->key }}</span>
        @endif
        @if ($slot->owner)
            <span class="text-xs text-gray-400 truncate">{{ $slot->owner->name }}</span>
        @endif
        @if ($slot->mode === $M::MODE_OVERWRITE)
            <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-800">&#9888; overwrite</span>
        @endif
    </div>
    <div class="flex shrink-0 items-center gap-2 text-xs text-gray-500">
        @if (($slot->proposed_count ?? 0) > 0)
            <span class="rounded-full px-2 py-0.5 bg-amber-100 text-amber-800 font-medium">{{ $slot->proposed_count }} proposed</span>
        @endif
        <span>{{ $slot->activeVersion ? 'v'.$slot->activeVersion->version : 'no active' }}</span>
    </div>
</a>
