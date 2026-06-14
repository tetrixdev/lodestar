<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Settings') }}</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @php
                $items = [
                    [
                        'href' => route('profile.edit'),
                        'title' => 'Profile',
                        'desc' => 'Your name, email and password.',
                    ],
                    [
                        'href' => route('agent-tokens.index'),
                        'title' => 'Connect agent',
                        'desc' => 'Mint per-machine tokens so your coding agents can reach the board over MCP.',
                    ],
                    [
                        'href' => route('github.index'),
                        'title' => 'GitHub connections',
                        'desc' => 'Link the accounts and tokens used to read your repositories.',
                    ],
                    [
                        'href' => route('skills.index'),
                        'title' => 'Skills',
                        'desc' => 'View the system prompts that drive each loop phase, and fork them to edit.',
                    ],
                ];
            @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach ($items as $item)
                    <a href="{{ $item['href'] }}"
                       class="block bg-white shadow-sm sm:rounded-lg p-5 hover:shadow transition">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-medium text-gray-900">{{ $item['title'] }}</h3>
                            <span class="text-gray-300">&rarr;</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ $item['desc'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
