<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A tiny dependency-free line diff (LCS) for comparing two playbook-version bodies.
 * Returns a flat list of rows the UI renders: each row is ['op' => same|add|del,
 * 'old' => ?int, 'new' => ?int, 'text' => string].
 */
class LineDiff
{
    /**
     * @return list<array{op: string, old: int|null, new: int|null, text: string}>
     */
    public static function between(string $oldText, string $newText): array
    {
        // An empty body is zero lines, not one blank line (avoids a spurious row).
        $old = $oldText === '' ? [] : (preg_split("/\r\n|\n|\r/", $oldText) ?: []);
        $new = $newText === '' ? [] : (preg_split("/\r\n|\n|\r/", $newText) ?: []);

        $lcs = self::lcsTable($old, $new);

        // Walk the table backwards into an ordered row list.
        $rows = [];
        $i = count($old);
        $j = count($new);

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                $rows[] = ['op' => 'same', 'old' => $i, 'new' => $j, 'text' => $old[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                $rows[] = ['op' => 'add', 'old' => null, 'new' => $j, 'text' => $new[$j - 1]];
                $j--;
            } else {
                $rows[] = ['op' => 'del', 'old' => $i, 'new' => null, 'text' => $old[$i - 1]];
                $i--;
            }
        }

        return array_reverse($rows);
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return array<int, array<int, int>>
     */
    private static function lcsTable(array $old, array $new): array
    {
        $m = count($old);
        $n = count($new);
        $table = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $table[$i][$j] = $old[$i - 1] === $new[$j - 1]
                    ? $table[$i - 1][$j - 1] + 1
                    : max($table[$i - 1][$j], $table[$i][$j - 1]);
            }
        }

        return $table;
    }
}
