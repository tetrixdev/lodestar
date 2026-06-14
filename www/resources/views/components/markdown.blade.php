@props(['content' => null])

<div class="text-sm text-gray-700 space-y-2 [&_h1]:text-lg [&_h2]:text-base [&_h1]:font-semibold [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:rounded [&_a]:text-indigo-600 [&_a]:underline [&_pre]:bg-gray-100 [&_pre]:p-3 [&_pre]:rounded [&_pre]:overflow-x-auto">
    {!! \Illuminate\Support\Str::markdown((string) $content) !!}
</div>
