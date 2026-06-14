<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Projects</h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- create --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-900 mb-4">New project</h3>
                <form method="POST" action="{{ route('projects.store') }}" class="flex flex-col sm:flex-row gap-3">
                    @csrf
                    <input name="name" required placeholder="Project name"
                           class="flex-1 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    <input name="primary_goal" placeholder="Primary goal (optional)"
                           class="flex-1 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                    <x-primary-button>Create</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            {{-- list --}}
            <div class="bg-white shadow-sm sm:rounded-lg divide-y">
                @forelse ($projects as $project)
                    <a href="{{ route('projects.show', $project) }}"
                       class="flex items-center justify-between p-5 hover:bg-gray-50 transition">
                        <div>
                            <div class="font-medium text-gray-900">{{ $project->name }}</div>
                            @if ($project->primary_goal)
                                <div class="text-sm text-gray-500 line-clamp-1">{{ $project->primary_goal }}</div>
                            @endif
                        </div>
                        <span class="text-sm text-gray-400">{{ $project->tasks_count }} tasks</span>
                    </a>
                @empty
                    <p class="p-5 text-gray-500">No projects yet — create one above.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
