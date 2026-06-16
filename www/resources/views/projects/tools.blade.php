<x-app-layout>
    @php $T = \App\Models\ProjectTool::class; @endphp
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Projects', 'url' => route('projects.index')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Tools'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Tools</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">Saved.</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-1">
                <p class="text-sm text-gray-600">
                    Programs the agent should install (e.g. <code>pandoc</code>) and small commands it can use
                    (e.g. a thin wrapper around a big API so it doesn't load a huge MCP). The agent fetches these
                    during workspace setup.
                </p>
                <p class="text-xs text-gray-400">
                    Agent fetch:
                    <code class="bg-gray-100 px-1 rounded">curl -H "Authorization: Bearer &lt;token&gt;" {{ route('api.projects.tools', $project) }}</code>
                </p>
                <p class="text-[11px] text-amber-700">⚠ These run shell on the agent's machine — only approvers can edit them.</p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <h3 class="font-semibold text-gray-800">Configured tools</h3>
                @forelse ($tools as $tool)
                    <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <span class="text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-gray-100 text-gray-600">{{ $tool->kind }}</span>
                                <code class="text-sm font-medium text-gray-800">{{ $tool->name }}</code>
                                @if ($tool->last_status)
                                    <span class="ml-1 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5
                                        @class([
                                            'bg-green-100 text-green-800' => $tool->last_status === 'ok',
                                            'bg-amber-100 text-amber-800' => $tool->last_status === 'missing',
                                            'bg-red-100 text-red-700' => $tool->last_status === 'error',
                                            'bg-gray-100 text-gray-500' => $tool->last_status === 'unknown',
                                        ])"
                                        title="reported {{ optional($tool->last_checked_at)->diffForHumans() }}">{{ $tool->last_status }}</span>
                                @endif
                                @if ($tool->description)<p class="text-xs text-gray-500">{{ $tool->description }}</p>@endif
                            </div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('tools.destroy', [$project, $tool]) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gray-400 hover:text-red-600">remove</button>
                                </form>
                            @endif
                        </div>
                        @if ($tool->check)<pre class="mt-1 text-[11px] text-gray-500 bg-gray-50 rounded p-2 whitespace-pre-wrap">check: {{ $tool->check }}</pre>@endif
                        <pre class="mt-1 text-[11px] text-gray-700 bg-gray-50 rounded p-2 whitespace-pre-wrap break-words max-h-40 overflow-y-auto">{{ $tool->run }}</pre>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No tools configured.</p>
                @endforelse
            </div>

            @if ($canManage)
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <h3 class="font-semibold text-gray-800">Add a tool</h3>
                    <form method="POST" action="{{ route('tools.store', $project) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <x-input-label for="t-kind" value="Kind" />
                                <x-select id="t-kind" name="kind" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    @foreach ($kinds as $k)<option value="{{ $k }}">{{ ucfirst($k) }}</option>@endforeach
                                </x-select>
                            </div>
                            <div>
                                <x-input-label for="t-name" value="Name" />
                                <x-text-input id="t-name" name="name" type="text" class="mt-1 block w-full text-sm" placeholder="pandoc / sentry-issue" />
                            </div>
                            <div>
                                <x-input-label for="t-desc" value="Description" />
                                <x-text-input id="t-desc" name="description" type="text" class="mt-1 block w-full text-sm" placeholder="when to use it" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="t-check" value="Check (program only — how to detect it; optional)" />
                            <x-text-input id="t-check" name="check" type="text" class="mt-1 block w-full text-sm" placeholder="pandoc --version" />
                        </div>
                        <div>
                            <x-input-label for="t-run" value="Run (program: install command · command: the script body)" />
                            <textarea id="t-run" name="run" rows="4" class="mt-1 block w-full text-sm rounded-md border-gray-300 font-mono"></textarea>
                            <x-input-error :messages="$errors->get('run')" class="mt-1" />
                        </div>
                        <x-primary-button>Add tool</x-primary-button>
                    </form>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
