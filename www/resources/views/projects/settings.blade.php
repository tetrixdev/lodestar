<x-app-layout>
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Projects', 'url' => route('projects.index')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Settings'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Settings</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            {{-- Details + team assignment --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header><h3 class="text-lg font-medium text-gray-900">Project details</h3></header>

                <form method="POST" action="{{ route('projects.update', $project) }}" class="mt-6 space-y-4">
                    @csrf @method('PATCH')
                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                      :value="old('name', $project->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="description" :value="__('Description')" />
                        <textarea id="description" name="description" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $project->description) }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="primary_goal" :value="__('Primary goal')" />
                        <textarea id="primary_goal" name="primary_goal" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('primary_goal', $project->primary_goal) }}</textarea>
                        <x-input-error :messages="$errors->get('primary_goal')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="stack" :value="__('Stack')" />
                        <x-select id="stack" name="stack"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="" @selected(! $project->stack)>— none —</option>
                            @foreach (\App\Models\Playbook::STACK_PACKS as $pack)
                                <option value="{{ $pack }}" @selected($project->stack === $pack)>{{ ucfirst($pack) }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-gray-500">Tags the project's framework so its structure pack steers plan / develop / review.</p>
                        <x-input-error :messages="$errors->get('stack')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="team_id" :value="__('Team')" />
                        @if ($isOwner)
                            <x-select id="team_id" name="team_id"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="" @selected($project->team_id === null)>Personal (no team)</option>
                                @foreach ($teams as $team)
                                    <option value="{{ $team->id }}" @selected($project->team_id === $team->id)>{{ $team->name }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('team_id')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-600">
                                {{ $project->team?->name ?? 'Personal (no team)' }} — only the owner can change this.
                            </p>
                        @endif
                    </div>

                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                </form>
            </div>

            {{-- Approvers --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">Project approvers</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Who may approve this project’s playbook changes. The owner always can.
                    </p>
                </header>

                @if ($project->team === null)
                    <p class="mt-4 text-sm text-gray-600">
                        This is a personal project — only the owner can approve.
                    </p>
                @else
                    <ul class="mt-4 divide-y">
                        @foreach ($project->members as $member)
                            <li class="py-3 flex items-center justify-between gap-3 text-sm">
                                <div class="min-w-0">
                                    <span class="font-medium text-gray-900">{{ $member->name }}</span>
                                    <span class="text-gray-500">— {{ $member->email }}</span>
                                    @if ($member->pivot->can_approve_prompts)
                                        <span class="ml-1 text-xs uppercase tracking-wide text-indigo-600">approves</span>
                                    @endif
                                </div>
                                @if ($isOwner)
                                    <form method="POST" action="{{ route('projects.approvers.remove', [$project, $member]) }}"
                                          onsubmit="return confirm('Remove this approver?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                        @if ($project->members->isEmpty())
                            <li class="py-3 text-sm text-gray-600">No extra approvers yet.</li>
                        @endif
                    </ul>

                    @if ($isOwner && $candidates->isNotEmpty())
                        <form method="POST" action="{{ route('projects.approvers.add', $project) }}"
                              class="mt-6 border-t pt-6 flex flex-col sm:flex-row gap-3 sm:items-end">
                            @csrf
                            <div class="flex-1">
                                <x-input-label for="user_id" :value="__('Add a team member')" />
                                <x-select id="user_id" name="user_id"
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} — {{ $candidate->email }}</option>
                                    @endforeach
                                </x-select>
                                <x-input-error :messages="$errors->get('user_id')" class="mt-2" />
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="can_approve_prompts" value="1"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                Can approve
                            </label>
                            <x-primary-button>{{ __('Add') }}</x-primary-button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
