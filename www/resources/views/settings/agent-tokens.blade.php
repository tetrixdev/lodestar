<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Connect a coding agent') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Just-created token: shown exactly once. --}}
            @if (session('plain_token'))
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg border-l-4 border-green-500">
                    <h3 class="text-lg font-medium text-gray-900">
                        Token for “{{ session('plain_token_name') }}” created
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Copy it now — you won't be able to see it again.
                    </p>
                    <pre class="mt-3 p-3 bg-gray-100 rounded text-sm overflow-x-auto select-all">{{ session('plain_token') }}</pre>
                    <p class="mt-3 text-sm text-gray-600">
                        Point your coding agent's MCP config at
                        <code class="bg-gray-100 px-1 rounded">{{ url('/mcp') }}</code>
                        and send this token as <code class="bg-gray-100 px-1 rounded">Authorization: Bearer &lt;token&gt;</code>.
                    </p>
                </div>
            @endif

            {{-- Create a new per-machine token. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <header>
                        <h3 class="text-lg font-medium text-gray-900">Create a token</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Mint one token per machine or agent. Each is revocable on its
                            own, so you can disconnect a laptop without affecting the rest.
                        </p>
                    </header>

                    <form method="POST" action="{{ route('agent-tokens.store') }}" class="mt-6 flex items-end gap-4">
                        @csrf
                        <div class="flex-1">
                            <x-input-label for="name" :value="__('Machine / agent name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          placeholder="e.g. laptop, ci-runner" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                    </form>
                </div>
            </div>

            {{-- Existing tokens. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Your tokens</h3>
                </header>

                @if ($tokens->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">No tokens yet.</p>
                @else
                    <table class="mt-4 w-full text-sm text-left">
                        <thead class="text-gray-500 border-b">
                            <tr>
                                <th class="py-2">Name</th>
                                <th class="py-2">Last used</th>
                                <th class="py-2">Created</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr class="border-b last:border-0">
                                    <td class="py-2 font-medium text-gray-900">{{ $token->name }}</td>
                                    <td class="py-2 text-gray-600">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                    <td class="py-2 text-gray-600">{{ $token->created_at->toDateString() }}</td>
                                    <td class="py-2 text-right">
                                        <form method="POST" action="{{ route('agent-tokens.destroy', $token->id) }}"
                                              onsubmit="return confirm('Revoke this token? Any agent using it will lose access.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-600 hover:text-red-800">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
