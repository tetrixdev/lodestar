{{--
  Decide on a single proposed PlaybookVersion: approve / approve-with-edits / reject.
  Self-contained so it works for a v1 proposal (no prior active version to diff
  against) just as well as for a later proposal reviewed via Compare & review.

  Expects: $version (a proposed PlaybookVersion), $isPhase (bool).
--}}
@php $M = \App\Models\Playbook::class; @endphp
<div class="border border-amber-200 bg-amber-50/40 rounded-lg p-4 space-y-3" x-data="{ editing: false }">
    <div class="flex items-center gap-2 text-sm">
        <span class="text-gray-700 font-medium">v{{ $version->version }}</span>
        <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-800">proposed{{ $version->proposed_by_ai ? ' · AI' : '' }}</span>
        <span class="text-xs text-gray-500 truncate">{{ $version->author?->name ?? 'system' }} · {{ $version->created_at->diffForHumans() }}</span>
    </div>
    @if ($version->note)
        <p class="text-xs text-gray-600">Note: {{ $version->note }}</p>
    @endif

    {{-- the proposed body itself, so a v1 proposal (nothing to diff) is still reviewable in place --}}
    <pre class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-white border border-gray-100 rounded-md p-3 max-h-[40vh] overflow-y-auto">{{ $version->body }}</pre>

    <div class="flex flex-wrap items-center gap-2" x-show="!editing">
        <form method="POST" action="{{ route('playbooks.versions.approve', $version) }}">
            @csrf
            <button class="rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-500">Approve</button>
        </form>
        <button type="button" @click="editing = true" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Approve with edits</button>
        <form method="POST" action="{{ route('playbooks.versions.reject', $version) }}">
            @csrf
            <button class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Reject</button>
        </form>
    </div>

    {{-- approve-with-edits: publishes the amended body as a new active version, archiving this proposal --}}
    <form x-show="editing" x-cloak method="POST" action="{{ route('playbooks.versions.approveEdits', $version) }}" class="space-y-3">
        @csrf
        <p class="text-xs text-gray-500">Your edits publish a new active version; this proposal is archived as "amended into" it.</p>
        <div>
            <x-input-label :for="'ae-title-'.$version->id" value="Title" />
            <x-text-input :id="'ae-title-'.$version->id" name="title" type="text" class="mt-1 block w-full" :value="old('title', $version->title)" />
            <x-input-error :messages="$errors->get('title')" class="mt-1" />
        </div>
        <div>
            <x-input-label :for="'ae-summary-'.$version->id" value="Summary (one line)" />
            <x-text-input :id="'ae-summary-'.$version->id" name="summary" type="text"
                          class="mt-1 block w-full disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                          :value="old('summary', $version->summary)" :disabled="$isPhase" />
            @if ($isPhase)
                <p class="text-[11px] text-gray-400 mt-1">Not used for phase playbooks (not catalogued).</p>
            @endif
        </div>
        <div x-data="{ mode: '{{ old('mode', $version->mode) }}' }">
            <x-input-label value="How this layer combines" />
            <x-select name="mode" x-model="mode" class="mt-1 block w-full sm:w-80">
                <option value="{{ $M::MODE_APPEND }}">Append — add onto the layers above it</option>
                <option value="{{ $M::MODE_OVERWRITE }}">Overwrite — discard everything above it</option>
            </x-select>
            <p x-show="mode === '{{ $M::MODE_OVERWRITE }}'" x-cloak
               class="mt-2 flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-2 text-xs text-amber-800">
                <span class="text-base leading-none">&#9888;</span>
                <span><strong>Overwrite is a full override</strong> — discards everything above this layer.</span>
            </p>
        </div>
        <div>
            <x-input-label :for="'ae-body-'.$version->id" value="Body (markdown)" />
            <textarea :id="'ae-body-'.$version->id" name="body" rows="10"
                      class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body', $version->body) }}</textarea>
            <x-input-error :messages="$errors->get('body')" class="mt-1" />
        </div>
        <div class="flex items-center gap-3">
            <x-primary-button>Publish amended version</x-primary-button>
            <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
        </div>
    </form>
</div>
