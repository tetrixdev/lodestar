<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('MCP reference') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <p class="text-sm text-gray-600">
                    Every tool an agent can call over the Lodestar MCP, grouped. Parameters are generated from each
                    tool's own schema; the example shows what a call returns. All tools are scoped to your token's user.
                </p>
            </div>

            @foreach ($groups as $group => $tools)
                <div class="space-y-3">
                    <h3 class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide">{{ $group }}</h3>

                    @foreach ($tools as $tool)
                        <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                            <div>
                                <code class="text-sm font-semibold text-indigo-700">{{ $tool['name'] }}</code>
                                <p class="text-sm text-gray-600 mt-1">{{ $tool['description'] }}</p>
                            </div>

                            @if ($tool['params'])
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-xs">
                                        <thead class="text-gray-400 text-left">
                                            <tr>
                                                <th class="pr-4 py-1 font-medium">Parameter</th>
                                                <th class="pr-4 py-1 font-medium">Type</th>
                                                <th class="pr-4 py-1 font-medium">Required</th>
                                                <th class="py-1 font-medium">Description</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-gray-700 align-top">
                                            @foreach ($tool['params'] as $p)
                                                <tr class="border-t border-gray-100">
                                                    <td class="pr-4 py-1.5"><code class="text-gray-800">{{ $p['name'] }}</code></td>
                                                    <td class="pr-4 py-1.5 text-gray-500">{{ $p['type'] }}</td>
                                                    <td class="pr-4 py-1.5">
                                                        @if ($p['required'])
                                                            <span class="text-red-600 font-medium">yes</span>
                                                        @else
                                                            <span class="text-gray-400">no</span>
                                                        @endif
                                                    </td>
                                                    <td class="py-1.5">
                                                        {{ $p['description'] }}
                                                        @if ($p['enum'])
                                                            <span class="text-gray-400">— one of: <code>{{ $p['enum'] }}</code></span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-xs text-gray-400 italic">No parameters.</p>
                            @endif

                            @if ($tool['example'])
                                <div>
                                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide mb-1">Returns</p>
                                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-50 rounded-md p-3">{{ $tool['example'] }}</pre>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach

        </div>
    </div>
</x-app-layout>
