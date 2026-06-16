<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <x-breadcrumb :trail="[
                    ['label' => 'Projects', 'url' => route('projects.index')],
                    ['label' => $project->name, 'url' => route('projects.show', $project)],
                    ['label' => 'Sessions'],
                ]" />
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Sessions</h2>
            </div>
            <a href="{{ route('work-sessions.create', $project) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Log session &rarr;</a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg divide-y">
                @forelse ($sessions as $session)
                    <a href="{{ route('work-sessions.show', $session) }}"
                       class="block p-5 hover:bg-gray-50 transition">
                        <div class="flex items-start justify-between gap-3">
                            <div class="font-medium text-gray-900">{{ $session->title }}</div>
                            <span class="shrink-0 text-xs text-gray-400">{{ optional($session->occurred_on)->toFormattedDateString() }}</span>
                        </div>
                        @if ($session->task)
                            <div class="mt-1 text-xs text-indigo-600">on {{ $session->task->title }}</div>
                        @endif
                        @if ($session->body)
                            <p class="mt-1 text-sm text-gray-500 line-clamp-2">{{ Str::limit(strip_tags($session->body), 160) }}</p>
                        @endif
                    </a>
                @empty
                    <p class="p-5 text-gray-500">No work sessions yet — <a href="{{ route('work-sessions.create', $project) }}" class="text-indigo-600 hover:underline">log the first one</a>.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
