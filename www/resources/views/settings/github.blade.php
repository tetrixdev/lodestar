<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('GitHub connections') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <header>
                        <h3 class="text-lg font-medium text-gray-900">Connect a GitHub account</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Paste a personal access token (repo scope). Add more than one to
                            keep work and personal accounts separate — each repository is read
                            through the connection you link it with.
                        </p>
                    </header>

                    <form method="POST" action="{{ route('github.store') }}" class="mt-6 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="label" :value="__('Label')" />
                            <x-text-input id="label" name="label" type="text" class="mt-1 block w-full"
                                          placeholder="work / personal" required />
                            <x-input-error :messages="$errors->get('label')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="token" :value="__('Personal access token')" />
                            <x-text-input id="token" name="token" type="password" class="mt-1 block w-full"
                                          placeholder="ghp_…" required />
                            <p class="mt-1 text-xs text-gray-600">
                                <a href="https://github.com/settings/tokens/new?description=Lodestar&scopes=repo"
                                   target="_blank" rel="noopener"
                                   class="text-indigo-600 hover:underline">Generate one on GitHub &rarr;</a>
                                — opens the token page with the <code class="bg-gray-100 px-1 rounded">repo</code>
                                scope pre-selected; copy it back here.
                            </p>
                            <x-input-error :messages="$errors->get('token')" class="mt-2" />
                        </div>
                        <x-primary-button>{{ __('Connect') }}</x-primary-button>
                    </form>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header><h3 class="text-lg font-medium text-gray-900">Your connections</h3></header>
                @if ($connections->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">No connections yet.</p>
                @else
                    <ul class="mt-4 divide-y">
                        @foreach ($connections as $c)
                            <li class="py-3 flex items-center justify-between gap-3 text-sm">
                                <div class="min-w-0 truncate">
                                    <span class="font-medium text-gray-900">{{ $c->label }}</span>
                                    <span class="text-gray-500">— {{ '@'.$c->github_login }}</span>
                                </div>
                                <form method="POST" action="{{ route('github.destroy', $c->id) }}" class="shrink-0"
                                      onsubmit="return confirm('Remove this connection? Repos read through it will stop updating.')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800">Remove</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
