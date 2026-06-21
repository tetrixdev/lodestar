{{-- AI & Embeddings status panel: operator opt-in, no key input (the key is an
     env secret). Shows whether the key is set/valid, per-type embedded/total
     counts, last-sync, and Test-key + Re-sync actions. --}}
<div class="mb-6 bg-white shadow-sm sm:rounded-lg p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <h3 class="font-medium text-gray-900">AI &amp; Embeddings</h3>
            @if ($embeddings['configured'])
                <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Key configured
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> No key
                </span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('settings.embeddings.test-key') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">
                    Test key
                </button>
            </form>
            <form method="POST" action="{{ route('settings.embeddings.resync') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md bg-gray-800 text-white hover:bg-gray-700">
                    Re-sync
                </button>
            </form>
        </div>
    </div>

    @if (session('embeddings_status'))
        @php($status = session('embeddings_status'))
        <div class="mt-3 rounded-md px-3 py-2 text-sm {{ $status['ok'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">
            {{ $status['message'] }}
        </div>
    @endif

    <p class="mt-3 text-sm text-gray-500">
        Semantic search over your projects, tasks, reviews and sessions. The key is an operator
        env secret (<code>OPENAI_API_KEY</code>) — there is no field to set it here. Model:
        <span class="font-mono text-gray-700">{{ $embeddings['model'] }}</span>.
        @if ($embeddings['last_sync'])
            Last sync: {{ \Illuminate\Support\Carbon::parse($embeddings['last_sync'])->diffForHumans() }}.
        @else
            Not yet synced.
        @endif
    </p>

    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach ($embeddings['counts'] as $row)
            <div class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                <div class="text-xs text-gray-500 capitalize truncate">{{ $row['label'] }}</div>
                <div class="text-sm font-semibold text-gray-900">{{ $row['embedded'] }}<span class="text-gray-400 font-normal"> / {{ $row['total'] }}</span></div>
            </div>
        @endforeach
    </div>
</div>
