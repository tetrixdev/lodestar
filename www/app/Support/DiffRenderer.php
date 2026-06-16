<?php

declare(strict_types=1);

namespace App\Support;

use SebastianBergmann\Diff\Differ;

/**
 * Turns diffs into render-ready row data for the review file viewer's Blade
 * partials. Nothing here emits HTML — the partials own the markup (and the
 * escaping); this just classifies each line and assigns old/new line numbers.
 *
 * A "row" is: ['type' => added|removed|unchanged|hunk, 'old' => ?int,
 * 'new' => ?int, 'text' => string]. A `hunk` row is a `@@ ... @@` separator and
 * carries no line numbers.
 */
class DiffRenderer
{
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
     * @return list<array{type:string,old:?int,new:?int,text:string}>
     */
    public function renderFullFile(?string $baseContent, string $headContent): array
    {
        $differ = new Differ(new class implements \SebastianBergmann\Diff\Output\DiffOutputBuilderInterface
        {
            public function getDiff(array $diff): string
            {
                return '';
            }
        });

        $diff = $differ->diffToArray($baseContent ?? '', $headContent);

        $rows = [];
        $oldLine = 1;
        $newLine = 1;

        foreach ($diff as [$text, $type]) {
            // diffToArray may prepend mixed-line-ending warnings — they're not
            // file content, so drop them.
            if ($type === Differ::DIFF_LINE_END_WARNING || $type === Differ::NO_LINE_END_EOF_WARNING) {
                continue;
            }

            // diffToArray keeps trailing newlines on each token; strip the single
            // line terminator for display (the partial renders one row per line).
            $text = preg_replace('/\r\n$|\n$|\r$/', '', (string) $text) ?? '';

            if ($type === Differ::ADDED) {
                $rows[] = ['type' => 'added', 'old' => null, 'new' => $newLine++, 'text' => $text];
            } elseif ($type === Differ::REMOVED) {
                $rows[] = ['type' => 'removed', 'old' => $oldLine++, 'new' => null, 'text' => $text];
            } else { // OLD / unchanged (warnings collapse here harmlessly)
                $rows[] = ['type' => 'unchanged', 'old' => $oldLine++, 'new' => $newLine++, 'text' => $text];
            }
        }

        return $rows;
    }
}
