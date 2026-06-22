@props(['content' => null, 'rich' => null])

{{--
    Renders markdown as prose. Pass `content` (raw markdown, rendered here) for a
    clean document, or `rich` (already-rendered HTML — e.g. a caxy/php-htmldiff
    result with inline <ins>/<del>) which is injected as-is. The ins/del styling
    lives here so it's scoped to the prose container alongside the other element
    styles: insertions read green, deletions red + struck-through.

    Mobile table reflow: a GFM markdown `<table>` becomes a horizontally-scrollable
    block on small screens (`[&_table]:block [&_table]:overflow-x-auto`) so wide
    tables stay readable at ~360px, and returns to a normal full-width table at
    `sm:` and up — so desktop is unchanged. (A horizontal-scroll container is the
    chosen approach over a fragile stacked-card reflow; it's one CSS change here and
    so covers every markdown surface at once.) Borders/padding make the cells legible.
--}}
<div class="text-sm text-gray-700 space-y-2 break-words [&_h1]:text-lg [&_h2]:text-base [&_h1]:font-semibold [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:rounded [&_a]:text-indigo-600 [&_a]:underline [&_pre]:bg-gray-100 [&_pre]:p-3 [&_pre]:rounded [&_pre]:overflow-x-auto [&_ins]:bg-emerald-100 [&_ins]:text-emerald-800 [&_ins]:no-underline [&_ins]:rounded-sm [&_ins]:px-0.5 [&_del]:bg-red-100 [&_del]:text-red-800 [&_del]:rounded-sm [&_del]:px-0.5 [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto sm:[&_table]:table [&_table]:border-collapse [&_th]:border [&_th]:border-gray-200 [&_th]:bg-gray-50 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_th]:whitespace-nowrap [&_td]:border [&_td]:border-gray-200 [&_td]:px-2 [&_td]:py-1 [&_td]:align-top">
    {!! $rich !== null ? $rich : \App\Support\Markdown::render((string) $content) !!}
</div>
