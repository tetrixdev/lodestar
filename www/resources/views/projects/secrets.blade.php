<x-app-layout>
    <x-slot name="header">
        <div>
            <x-breadcrumb :trail="[
                ['label' => 'Board', 'url' => route('board')],
                ['label' => $project->name, 'url' => route('projects.show', $project)],
                ['label' => 'Secrets'],
            ]" />
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }} — Secrets</h2>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-green-50 text-green-800 rounded-lg text-sm">Saved.</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-1">
                <p class="text-sm text-gray-600">
                    This project declares the <strong>keys</strong> it needs; you provide your own
                    <strong>values</strong> (encrypted, private to you). An agent imports your values for this
                    project out-of-band, so they never pass through the MCP/LLM.
                </p>
                <p class="text-xs text-gray-400">
                    Agent import (writes a file, never prints):
                    <code class="bg-gray-100 px-1 rounded">curl -H "Authorization: Bearer &lt;token&gt;" {{ route('api.projects.secrets', $project) }} -o .env.secrets</code>
                </p>
            </div>

            {{-- the manifest + your values --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                <h3 class="font-semibold text-gray-800">Required keys</h3>
                @forelse ($requirements as $req)
                    @php $val = $mine[$req->key] ?? null; @endphp
                    <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0 space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <code class="text-sm font-medium text-gray-800">{{ $req->key }}</code>
                                @if ($val)
                                    <span class="ml-2 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-green-100 text-green-800">provided</span>
                                @else
                                    <span class="ml-2 text-[10px] font-medium uppercase tracking-wide rounded px-1.5 py-0.5 bg-amber-100 text-amber-800">missing</span>
                                @endif
                                @if ($req->description)<p class="text-xs text-gray-500">{{ $req->description }}</p>@endif
                            </div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('secrets.requirements.destroy', [$project, $req->key]) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gray-400 hover:text-red-600">remove key</button>
                                </form>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <form method="POST" action="{{ route('secrets.values.store', $project) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="key" value="{{ $req->key }}">
                                <input type="password" name="value" placeholder="{{ $val ? '•••••• (set — replace)' : 'your value' }}"
                                       class="text-sm rounded-md border-gray-300 w-64" autocomplete="off" />
                                <label class="text-xs text-gray-500 flex items-center gap-1">
                                    <input type="checkbox" name="project_scoped" value="1" class="rounded border-gray-300"> only this project
                                </label>
                                <x-primary-button class="!py-1.5 !text-xs">{{ $val ? 'Update' : 'Set' }}</x-primary-button>
                            </form>
                            @if ($val)
                                <form method="POST" action="{{ route('secrets.values.destroy', [$project, $val]) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gray-400 hover:text-red-600 pb-2">clear mine</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 italic">No required keys yet.</p>
                @endforelse
            </div>

            {{-- approver: add a key --}}
            @if ($canManage)
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <h3 class="font-semibold text-gray-800">Add a required key</h3>
                    <form method="POST" action="{{ route('secrets.requirements.store', $project) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div>
                            <x-input-label for="r-key" value="Key" />
                            <x-text-input id="r-key" name="key" type="text" class="mt-1 w-56 text-sm" placeholder="STRIPE_SECRET" />
                        </div>
                        <div class="flex-1 min-w-48">
                            <x-input-label for="r-desc" value="Description" />
                            <x-text-input id="r-desc" name="description" type="text" class="mt-1 block w-full text-sm" placeholder="What it's for" />
                        </div>
                        <x-primary-button class="!py-1.5 !text-xs">Add key</x-primary-button>
                    </form>
                    <x-input-error :messages="$errors->get('key')" class="mt-1" />
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
