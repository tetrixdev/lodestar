@props(['trail' => []])

{{--
  A real breadcrumb trail: Projects › Lodestar › #12 Title.
  $trail is an ordered array of ['label' => string, 'url' => ?string].
  Every crumb but the last links (if it has a url); the last is the current
  page, shown plain. Sits as a small kicker above the page's big H2.
--}}
<nav class="flex items-center flex-wrap gap-x-1.5 gap-y-0.5 text-sm text-gray-400 mb-1" aria-label="Breadcrumb">
    @foreach ($trail as $crumb)
        @if (! $loop->first)
            <span class="text-gray-300 select-none">&rsaquo;</span>
        @endif
        @if (! $loop->last && ! empty($crumb['url']))
            <a href="{{ $crumb['url'] }}" class="hover:text-gray-600 truncate max-w-[14rem]">{{ $crumb['label'] }}</a>
        @else
            <span @class(['truncate max-w-[20rem]', 'text-gray-600 font-medium' => $loop->last]) aria-current="{{ $loop->last ? 'page' : 'false' }}">{{ $crumb['label'] }}</span>
        @endif
    @endforeach
</nav>
