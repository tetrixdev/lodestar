<x-app-layout>
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Projects', 'url' => route('projects.index')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Sessions', 'url' => route('work-sessions.index', $project)],
                ['label' => 'New'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Log a session</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('work-sessions.store', $project) }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="title" value="Title" />
                        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full"
                                      :value="old('title')" required autofocus />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="occurred_on" value="Date" />
                            <x-text-input id="occurred_on" name="occurred_on" type="date" class="mt-1 block w-full"
                                          :value="old('occurred_on')" />
                            <x-input-error :messages="$errors->get('occurred_on')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="task_id" value="Task (optional)" />
                            <x-select id="task_id" name="task_id"
                                    class="mt-1 block w-full">
                                <option value="">— none —</option>
                                @foreach ($tasks as $task)
                                    <option value="{{ $task->id }}" @selected((string) old('task_id') === (string) $task->id)>{{ $task->title }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('task_id')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="body_summary" value="Summary" />
                        <p class="text-xs text-gray-400">A 1–2 sentence TL;DR — required when there's detail below.</p>
                        <textarea id="body_summary" name="body_summary" rows="2"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body_summary') }}</textarea>
                        <x-input-error :messages="$errors->get('body_summary')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="body" value="Detail (markdown)" />
                        <textarea id="body" name="body" rows="10"
                                  class="mt-1 block w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('body') }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save session</x-primary-button>
                        <a href="{{ route('work-sessions.index', $project) }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
