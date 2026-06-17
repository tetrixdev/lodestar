<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Single place markdown is turned into HTML for display, so every rendered-
 * markdown surface (section context, finding detail, file Preview mode, the rich
 * diff) treats ```mermaid fences identically: they become a `<pre class="mermaid">`
 * container holding the *decoded* diagram source, which the client-side
 * window.renderMermaid() helper turns into an SVG. Everything else is the default
 * Str::markdown() output.
 */
class Markdown
{
    /** Render raw markdown to display HTML, with mermaid fences as diagram containers. */
    public static function render(string $content): string
    {
        return self::promoteMermaid(Str::markdown($content));
    }

    /**
     * Rewrite the `<pre><code class="language-mermaid">…</code></pre>` blocks that
     * Str::markdown() emits for a ```mermaid fence into `<pre class="mermaid">…</pre>`
     * carrying the HTML-decoded source. The markdown engine HTML-escapes the fence
     * body (e.g. `-->` becomes `--&gt;`); mermaid needs the raw text, so we decode it.
     *
     * Used by both {@see render()} and the rich-diff path in {@see DiffRenderer},
     * the latter to keep diagram blocks out of the inline HTML diff.
     */
    public static function promoteMermaid(string $html): string
    {
        return preg_replace_callback(
            '#<pre><code class="language-mermaid">(.*?)</code></pre>#s',
            fn (array $m) => '<pre class="mermaid">'.html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5).'</pre>',
            $html,
        ) ?? $html;
    }
}
