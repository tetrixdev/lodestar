{{--
    Guided setup checklist. Rendered with $onboarding = [
        'complete' => bool,            // all four steps done
        'steps' => [['label','done','href','help'], ...],
        'doneCount' => int,
    ]. The whole banner hides once every step is done.
--}}
@if (! ($onboarding['complete'] ?? true))
    <section class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4 border-l-4 border-indigo-400">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">Get set up</h3>
                <p class="mt-1 text-sm text-gray-500">
                    A few quick steps to get Lodestar driving your work.
                </p>
            </div>
            <span class="shrink-0 text-xs font-medium text-gray-400">
                {{ $onboarding['doneCount'] }} / {{ count($onboarding['steps']) }} done
            </span>
        </div>

        <ol class="space-y-2">
            @foreach ($onboarding['steps'] as $i => $step)
                <li>
                    <a href="{{ $step['href'] }}"
                       class="flex items-start gap-3 -mx-2 px-2 py-2 rounded hover:bg-gray-50 transition">
                        @if ($step['done'])
                            <span class="shrink-0 mt-0.5 flex size-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.6a1 1 0 0 1-1.42.004l-3.5-3.5a1 1 0 1 1 1.414-1.414l2.79 2.79 6.796-6.888a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd"/></svg>
                            </span>
                        @else
                            <span class="shrink-0 mt-0.5 flex size-5 items-center justify-center rounded-full border border-gray-300 text-xs font-medium text-gray-400">
                                {{ $i + 1 }}
                            </span>
                        @endif
                        <span class="min-w-0">
                            <span class="block text-sm font-medium {{ $step['done'] ? 'text-gray-400 line-through' : 'text-gray-800' }}">
                                {{ $step['label'] }}
                            </span>
                            @if (! $step['done'] && ! empty($step['help']))
                                <span class="block text-xs text-gray-500">{{ $step['help'] }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
