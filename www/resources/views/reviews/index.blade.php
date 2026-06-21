<x-app-layout>
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Board', 'url' => route('board')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Reviews'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Reviews</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg divide-y">
                @forelse ($reviews as $review)
                    @php
                        $signed = $review->sections()->where('status', 'signed_off')->count();
                    @endphp
                    <a href="{{ route('reviews.show', $review) }}"
                       class="flex items-center justify-between p-5 hover:bg-gray-50 transition">
                        <div>
                            <div class="font-medium text-gray-900">{{ $review->title }}</div>
                            <div class="text-xs text-gray-500">
                                <code class="bg-gray-100 px-1 rounded">{{ $review->base_ref ?? '?' }}</code>
                                &hellip;
                                <code class="bg-gray-100 px-1 rounded">{{ $review->head_ref ?? '?' }}</code>
                                · {{ ucfirst(str_replace('_', ' ', $review->status)) }}
                            </div>
                        </div>
                        <span class="text-sm text-gray-400">{{ $signed }}/{{ $review->sections_count }} signed off</span>
                    </a>
                @empty
                    <p class="p-5 text-gray-500">No reviews yet — an agent prepares these via MCP, or one will appear here.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
