<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('teams.index') }}" class="text-gray-400 hover:text-gray-600">&larr;</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight truncate">{{ $team->name }}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            {{-- Settings (owner only) --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Team settings</h3>
                    @unless ($isOwner)
                        <p class="mt-1 text-sm text-gray-600">Only the owner can change these.</p>
                    @endunless
                </header>

                @if ($isOwner)
                    <form method="POST" action="{{ route('teams.update', $team) }}" class="mt-6 space-y-4">
                        @csrf @method('PATCH')
                        <div>
                            <x-input-label for="name" :value="__('Team name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          :value="old('name', $team->name)" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="allow_personal_instructions" value="1"
                                   @checked($team->allow_personal_instructions)
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Allow members to add personal instructions
                        </label>
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                    </form>
                @else
                    <dl class="mt-4 text-sm text-gray-700 space-y-1">
                        <div><span class="text-gray-500">Owner:</span> {{ $team->owner->name }}</div>
                        <div><span class="text-gray-500">Personal instructions:</span>
                            {{ $team->allow_personal_instructions ? 'allowed' : 'off' }}</div>
                    </dl>
                @endif
            </div>

            {{-- Members --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header><h3 class="text-lg font-medium text-gray-900">Members</h3></header>

                <ul class="mt-4 divide-y">
                    @foreach ($team->members as $member)
                        @php $isTeamOwner = $member->id === $team->owner_user_id; @endphp
                        <li class="py-3 flex flex-wrap items-center justify-between gap-3 text-sm">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-900">{{ $member->name }}</span>
                                <span class="text-gray-500">— {{ $member->email }}</span>
                                @if ($isTeamOwner)
                                    <span class="ml-1 text-xs uppercase tracking-wide text-indigo-600">owner</span>
                                @endif
                            </div>

                            @if ($isOwner && ! $isTeamOwner)
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('teams.members.update', [$team, $member]) }}"
                                          class="flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <x-select name="role" class="rounded">
                                            <option value="member" @selected($member->pivot->role === 'member')>member</option>
                                            <option value="admin" @selected($member->pivot->role === 'admin')>admin</option>
                                        </x-select>
                                        <label class="flex items-center gap-1 text-xs text-gray-600">
                                            <input type="checkbox" name="can_approve_prompts" value="1"
                                                   @checked($member->pivot->can_approve_prompts)
                                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            approves
                                        </label>
                                        <button class="text-indigo-600 hover:text-indigo-800">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('teams.members.remove', [$team, $member]) }}"
                                          onsubmit="return confirm('Remove this member?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800">Remove</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-xs text-gray-500">
                                    {{ $member->pivot->role }}{{ $member->pivot->can_approve_prompts ? ' · approves' : '' }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($isOwner)
                    <form method="POST" action="{{ route('teams.members.add', $team) }}"
                          class="mt-6 border-t pt-6 space-y-3">
                        @csrf
                        <h4 class="text-sm font-medium text-gray-900">Add a member</h4>
                        <div class="flex flex-col sm:flex-row gap-3 sm:items-end">
                            <div class="flex-1">
                                <x-input-label for="email" :value="__('Email of an existing user')" />
                                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                              :value="old('email')" placeholder="teammate@example.com" required />
                            </div>
                            <div>
                                <x-input-label for="role" :value="__('Role')" />
                                <x-select id="role" name="role" class="mt-1 block rounded">
                                    <option value="member">member</option>
                                    <option value="admin">admin</option>
                                </x-select>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="can_approve_prompts" value="1"
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Can approve prompts
                        </label>
                        <x-input-error :messages="$errors->get('email')" />
                        <x-primary-button>{{ __('Add member') }}</x-primary-button>
                    </form>
                @endif
            </div>

            {{-- Danger zone --}}
            @if ($isOwner)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <header>
                        <h3 class="text-lg font-medium text-gray-900">Delete team</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Projects in this team become personal (owner-only) again. This cannot be undone.
                        </p>
                    </header>
                    <form method="POST" action="{{ route('teams.destroy', $team) }}" class="mt-4"
                          onsubmit="return confirm('Delete this team? Its projects become personal again.')">
                        @csrf @method('DELETE')
                        <x-danger-button>{{ __('Delete team') }}</x-danger-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
