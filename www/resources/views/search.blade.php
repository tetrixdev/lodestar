<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Search') }}</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="GET" action="{{ route('search') }}" class="flex gap-2">
                <input type="search" name="q" value="{{ $query }}" autofocus
                       placeholder="Search projects, tasks, reviews, sessions…"
                       class="flex-1 min-w-0 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       aria-label="Search query">
                <button type="submit"
                        class="shrink-0 inline-flex items-center px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                    Search
                </button>
            </form>

            @unless ($enabled)
                <div class="mt-6 rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                    Semantic search is not configured on this deployment (no embedding key). Ask an
                    operator to set <code>OPENAI_API_KEY</code> to enable it.
                </div>
            @endunless

            @if ($ran)
                <p class="mt-6 text-sm text-gray-500">{{ count($results) }} result{{ count($results) === 1 ? '' : 's' }} for &ldquo;{{ $query }}&rdquo;</p>

                <ul class="mt-3 space-y-3">
                    @forelse ($results as $result)
                        <li class="bg-white shadow-sm sm:rounded-lg p-4">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ str_replace('_', ' ', $result['type']) }}</span>
                                @if ($result['url'])
                                    <a href="{{ $result['url'] }}" class="font-medium text-indigo-600 hover:text-indigo-800 truncate">{{ $result['title'] }}</a>
                                @else
                                    <span class="font-medium text-gray-900 truncate">{{ $result['title'] }}</span>
                                @endif
                            </div>
                            @if ($result['snippet'])
                                <p class="mt-1 text-sm text-gray-500 break-words">{{ $result['snippet'] }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">No matches.</li>
                    @endforelse
                </ul>
            @endif
        </div>
    </div>
</x-app-layout>
