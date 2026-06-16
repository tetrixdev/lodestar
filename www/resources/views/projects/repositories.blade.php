<x-app-layout>
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Projects', 'url' => route('projects.index')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Repositories'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Repositories</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Linked repositories</h3>
                    <p class="mt-1 text-sm text-gray-600">The repos this project spans. Reviews compare branches within these.</p>
                </header>

                @if ($repositories->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">No repositories linked yet — add one below so reviews and the loop can run.</p>
                @else
                    <ul class="mt-4 divide-y">
                        @foreach ($repositories as $r)
                            <li class="py-3 flex items-center justify-between gap-3 text-sm">
                                <div class="font-mono">
                                    <span class="text-gray-900">{{ $r->full_name }}</span>
                                    <span class="text-gray-400">@ {{ $r->default_branch }}</span>
                                    <span class="ml-2 font-sans text-[11px] text-gray-500">via {{ $r->githubConnection->label }} ({{ '@'.$r->githubConnection->github_login }})</span>
                                </div>
                                <form method="POST" action="{{ route('repositories.destroy', [$project, $r]) }}"
                                      onsubmit="return confirm('Unlink this repo from the project?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800">Unlink</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header><h3 class="text-lg font-medium text-gray-900">Link a repository</h3></header>

                @if ($connections->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">
                        Connect a GitHub account first under
                        <a href="{{ route('github.index') }}" class="text-indigo-600 hover:underline">GitHub connections</a>.
                    </p>
                @else
                    <form method="POST" action="{{ route('repositories.store', $project) }}" class="mt-4 space-y-4 max-w-xl">
                        @csrf
                        <div>
                            <x-input-label for="full_name" :value="__('Repository (owner/name)')" />
                            <x-text-input id="full_name" name="full_name" type="text" class="mt-1 block w-full"
                                          placeholder="tetrixdev/lodestar" required />
                            <x-input-error :messages="$errors->get('full_name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="github_connection_id" :value="__('Read through connection')" />
                            <x-select id="github_connection_id" name="github_connection_id"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                @foreach ($connections as $c)
                                    <option value="{{ $c->id }}">{{ $c->label }} (@{{ $c->github_login }})</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('github_connection_id')" class="mt-2" />
                        </div>
                        <x-primary-button>{{ __('Link repository') }}</x-primary-button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
