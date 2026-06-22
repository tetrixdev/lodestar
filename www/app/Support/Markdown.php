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
        // Escape raw HTML in the source instead of passing it through: this content
        // is agent- and human-authored free text (finding details, notes, sessions),
        // so a literal `<select>` / `<script>` must render as visible text, never as a
        // live element in the operator's browser. (XSS / DOM-injection boundary.)
        return self::promoteMermaid(Str::markdown(self::normaliseNewlines($content), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]));
    }

    /**
     * Turn literal backslash-escape sequences in stored content into real
     * whitespace before the markdown engine sees them. Some MCP-authored content
     * (a finding `detail`, a section note/comment) arrives carrying a literal `\n`
     * — two characters, a backslash then an `n`, not a real newline — so it would
     * otherwise render as the visible text `\n\n- item` instead of a paragraph
     * break followed by a bullet. We convert ONLY OUTSIDE fenced/inline code spans
     * so a code sample that legitimately contains `\n` (a regex, a shell snippet)
     * is left intact.
     */
    public static function normaliseNewlines(string $content): string
    {
        // Split on fenced (```…```) and inline (`…`) code spans, keeping the
        // delimiters; convert escapes only in the non-code segments.
        $parts = preg_split('/(```.*?```|`[^`]*`)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $i => $part) {
            // Odd indices are the captured code spans — leave them untouched.
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = str_replace(['\\r\\n', '\\n', '\\t'], ["\n", "\n", "\t"], $part);
        }

        return implode('', $parts);
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
