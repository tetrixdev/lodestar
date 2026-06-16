@php
    /** @var list<array{type:string,old:?int,new:?int,text:string}> $rows */
    $rowClass = [
        'added' => 'bg-emerald-50',
        'removed' => 'bg-red-50',
        'unchanged' => '',
        'hunk' => 'bg-indigo-50/70 text-indigo-700 select-none',
    ];
    $marker = ['added' => '+', 'removed' => '-', 'unchanged' => ' ', 'hunk' => ''];
@endphp

<table class="w-full border-collapse font-mono text-xs leading-relaxed">
    <tbody>
        @foreach ($rows as $row)
            <tr class="{{ $rowClass[$row['type']] ?? '' }}">
                <td class="select-none w-10 px-2 text-right align-top text-gray-400 border-r border-gray-100">{{ $row['old'] ?? '' }}</td>
                <td class="select-none w-10 px-2 text-right align-top text-gray-400 border-r border-gray-100">{{ $row['new'] ?? '' }}</td>
                <td class="select-none w-4 px-1 text-center align-top {{ $row['type'] === 'added' ? 'text-emerald-600' : ($row['type'] === 'removed' ? 'text-red-600' : 'text-gray-300') }}">{{ $marker[$row['type']] ?? '' }}</td>
                <td class="px-2 align-top whitespace-pre-wrap break-all {{ $row['type'] === 'hunk' ? 'font-medium' : 'text-gray-800' }}">{{ $row['text'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
