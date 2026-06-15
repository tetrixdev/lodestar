<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Teams') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Create a team</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        A team shares projects and prompt-approval rights. You become its owner.
                    </p>
                </header>

                <form method="POST" action="{{ route('teams.store') }}" class="mt-6 flex flex-col sm:flex-row gap-3 sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="name" :value="__('Team name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                      placeholder="Acme Crew" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <x-primary-button>{{ __('Create') }}</x-primary-button>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header><h3 class="text-lg font-medium text-gray-900">Your teams</h3></header>
                @if ($teams->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">You’re not on any teams yet.</p>
                @else
                    <ul class="mt-4 divide-y">
                        @foreach ($teams as $team)
                            <li class="py-3">
                                <a href="{{ route('teams.show', $team) }}"
                                   class="flex items-center justify-between gap-3 hover:text-indigo-700">
                                    <span class="font-medium text-gray-900">{{ $team->name }}</span>
                                    <span class="text-sm text-gray-500">
                                        {{ $team->members_count }} {{ Str::plural('member', $team->members_count) }} ·
                                        {{ $team->projects_count }} {{ Str::plural('project', $team->projects_count) }} &rarr;
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
