<?php

declare(strict_types=1);

namespace App\Support;

use Caxy\HtmlDiff\HtmlDiff;
use Illuminate\Support\Str;

/**
 * Turns diffs into render-ready row data for the review file viewer's Blade
 * partials. Most methods here emit no HTML — the partials own the markup (and
 * the escaping); they just classify each line and assign old/new line numbers.
 * The one exception is {@see renderRichMarkdown()}, which produces an HTML
 * string (rendered markdown with inline <ins>/<del>) the partial trusts as-is.
 *
 * A "row" is: ['type' => added|removed|unchanged|hunk, 'old' => ?int,
 * 'new' => ?int, 'text' => string]. A `hunk` row is a `@@ ... @@` separator and
 * carries no line numbers.
 */
class DiffRenderer
{
    /**
     * Above this many lines on either side we don't run the O(m·n) LCS — the
     * controller falls back to the raw stored patch instead. {@see renderFullFile}.
     */
    public const FULL_FILE_LINE_LIMIT = 2000;

    /**
     * Parse a unified-diff `patch` (as GitHub returns it, with no file header)
     * into rows. We read the hunk headers ourselves so each line gets correct
     * old/new line numbers — simpler and more predictable than reconstructing a
     * full Diff object for display.
     *
     * @return list<array{type:string,old:?int,new:?int,text:string}>
     */
    public function renderPatch(string $patch): array
    {
        $rows = [];
        $oldLine = 0;
        $newLine = 0;

        foreach (preg_split('/\r\n|\r|\n/', $patch) as $line) {
            if (str_starts_with($line, '@@')) {
                // @@ -oldStart,oldCount +newStart,newCount @@ [section heading]
                if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m)) {
                    $oldLine = (int) $m[1];
                    $newLine = (int) $m[2];
                }
                $rows[] = ['type' => 'hunk', 'old' => null, 'new' => null, 'text' => $line];

                continue;
            }

            // GitHub patches have no "diff --git"/"index"/"+++"/"---" lines, but
            // guard against them anyway so a raw unified diff also renders.
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')
                || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                continue;
            }

            $marker = $line === '' ? ' ' : $line[0];
            $text = $line === '' ? '' : substr($line, 1);

            if ($marker === '+') {
                $rows[] = ['type' => 'added', 'old' => null, 'new' => $newLine++, 'text' => $text];
            } elseif ($marker === '-') {
                $rows[] = ['type' => 'removed', 'old' => $oldLine++, 'new' => null, 'text' => $text];
            } elseif ($marker === '\\') {
                // "\ No newline at end of file" — metadata, not content.
                continue;
            } else {
                $rows[] = ['type' => 'unchanged', 'old' => $oldLine++, 'new' => $newLine++, 'text' => $text];
            }
        }

        return $rows;
    }

    /**
     * Render the WHOLE head file with changed lines highlighted inline. Removed
     * lines (present in base, gone from head) are shown at their position with no
     * new-line number. For an added file base is null/empty (all added); a removed
     * file passes its base content as $headContent with $baseContent null — every
     * line then classes as removed.
     *
     * Backed by {@see LineDiff} (a dependency-free LCS) — op→type maps
     * same→unchanged, add→added, del→removed, so the diff-rows partial renders
     * identically to the previous sebastian/diff implementation.
     *
     * Returns null when either side exceeds {@see FULL_FILE_LINE_LIMIT}: the LCS
     * is O(m·n) in memory, so for very large files the controller shows the raw
     * stored patch instead rather than building a huge table.
     *
     * @return list<array{type:string,old:?int,new:?int,text:string}>|null
     */
    public function renderFullFile(?string $baseContent, string $headContent): ?array
    {
        if ($this->exceedsLineLimit($baseContent) || $this->exceedsLineLimit($headContent)) {
            return null;
        }

        // A single trailing newline is a line terminator, not a final empty
        // line — drop it so a file ending in "\n" doesn't gain a spurious blank
        // row (this matches the previous sebastian/diff line tokenisation).
        $base = preg_replace('/\r\n$|\n$|\r$/', '', $baseContent ?? '') ?? '';
        $head = preg_replace('/\r\n$|\n$|\r$/', '', $headContent) ?? '';

        $rows = [];
        foreach (LineDiff::between($base, $head) as $row) {
            $type = match ($row['op']) {
                'add' => 'added',
                'del' => 'removed',
                default => 'unchanged',
            };
            $rows[] = ['type' => $type, 'old' => $row['old'], 'new' => $row['new'], 'text' => $row['text']];
        }

        return $rows;
    }

    /**
     * The rich markdown diff: render base and head markdown to HTML with the same
     * engine <x-markdown> uses, then run an inline HTML diff so the result is the
     * rendered document with <ins>/<del> highlights woven in. Returns an HTML
     * string the file-modal partial trusts (the markdown engine + HtmlDiff own
     * the escaping).
     *
     * An ADDED file (no base) renders as the clean document — marking the whole
     * file as inserted is noise. A REMOVED file ($headContent null) renders the
     * base as a fully-deleted document. Returns null when HtmlDiff throws or
     * either side is too large, so the caller can fall back to the raw patch.
     */
    public function renderRichMarkdown(?string $baseContent, ?string $headContent): ?string
    {
        if ($this->exceedsLineLimit($baseContent) || $this->exceedsLineLimit($headContent)) {
            return null;
        }

        $baseHtml = $baseContent === null || $baseContent === '' ? '' : Str::markdown($baseContent);
        $headHtml = $headContent === null || $headContent === '' ? '' : Str::markdown($headContent);

        // Added file: nothing to diff against → the clean rendered document.
        if ($baseHtml === '') {
            return $headHtml;
        }
        // Removed file: render the base as wholly deleted.
        if ($headHtml === '') {
            return '<del class="diffdel">'.$baseHtml.'</del>';
        }

        try {
            return (new HtmlDiff($baseHtml, $headHtml))->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /** True when $content has more than FULL_FILE_LINE_LIMIT lines. */
    private function exceedsLineLimit(?string $content): bool
    {
        if ($content === null || $content === '') {
            return false;
        }

        return substr_count($content, "\n") + 1 > self::FULL_FILE_LINE_LIMIT;
    }
}
