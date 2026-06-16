<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DiffRenderer;
use PHPUnit\Framework\TestCase;

class DiffRendererTest extends TestCase
{
    private function types(array $rows): array
    {
        return array_map(fn ($r) => $r['type'], $rows);
    }

    public function test_render_patch_assigns_line_numbers_and_classes(): void
    {
        $patch = "@@ -1,3 +1,3 @@\n unchanged\n-old line\n+new line\n more context";
        $rows = (new DiffRenderer)->renderPatch($patch);

        $this->assertSame(['hunk', 'unchanged', 'removed', 'added', 'unchanged'], $this->types($rows));

        // First content line is old=1 new=1; the removed line advances old only,
        // the added line advances new only.
        $this->assertSame(['old' => 1, 'new' => 1], ['old' => $rows[1]['old'], 'new' => $rows[1]['new']]);
        $this->assertSame(['old' => 2, 'new' => null], ['old' => $rows[2]['old'], 'new' => $rows[2]['new']]);
        $this->assertSame(['old' => null, 'new' => 2], ['old' => $rows[3]['old'], 'new' => $rows[3]['new']]);
        $this->assertSame('old line', $rows[2]['text']);
        $this->assertSame('new line', $rows[3]['text']);
    }

    public function test_render_patch_handles_multiple_hunks(): void
    {
        $patch = "@@ -1,1 +1,1 @@\n-a\n+A\n@@ -10,1 +10,2 @@\n ctx\n+added";
        $rows = (new DiffRenderer)->renderPatch($patch);

        $this->assertSame(['hunk', 'removed', 'added', 'hunk', 'unchanged', 'added'], $this->types($rows));
        // Second hunk re-bases the line counters to 10.
        $this->assertSame(10, $rows[4]['old']);
        $this->assertSame(10, $rows[4]['new']);
        $this->assertSame(11, $rows[5]['new']);
    }

    public function test_render_full_file_modified_marks_added_and_removed(): void
    {
        $rows = (new DiffRenderer)->renderFullFile("one\ntwo\nthree\n", "one\nTWO\nthree\n");
        $types = $this->types($rows);

        $this->assertContains('added', $types);
        $this->assertContains('removed', $types);
        $this->assertContains('unchanged', $types);
        // "one" and "three" survive unchanged.
        $unchanged = array_values(array_filter($rows, fn ($r) => $r['type'] === 'unchanged'));
        $this->assertSame(['one', 'three'], array_map(fn ($r) => $r['text'], $unchanged));
    }

    public function test_render_full_file_added_file_is_all_added(): void
    {
        $rows = (new DiffRenderer)->renderFullFile(null, "alpha\nbeta\n");

        $this->assertSame(['added', 'added'], $this->types($rows));
        $this->assertNull($rows[0]['old']);
        $this->assertSame(1, $rows[0]['new']);
    }

    public function test_render_full_file_removed_file_is_all_removed(): void
    {
        // A removed file: its base content rendered with an empty head.
        $rows = (new DiffRenderer)->renderFullFile("gone\nalso gone\n", '');

        $this->assertSame(['removed', 'removed'], $this->types($rows));
        $this->assertNull($rows[0]['new']);
        $this->assertSame(1, $rows[0]['old']);
    }

    public function test_render_full_file_guards_against_huge_files(): void
    {
        // Either side over the line limit → null so the controller shows the patch.
        $huge = str_repeat("x\n", DiffRenderer::FULL_FILE_LINE_LIMIT + 1);

        $this->assertNull((new DiffRenderer)->renderFullFile(null, $huge));
        $this->assertNull((new DiffRenderer)->renderFullFile($huge, 'one line'));
    }

    public function test_rich_markdown_added_file_is_the_clean_document(): void
    {
        // No base → render the head as-is, with no whole-file <ins> noise.
        $html = (new DiffRenderer)->renderRichMarkdown(null, "# Title\n\nbody\n");

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringNotContainsString('<ins', $html);
    }

    public function test_rich_markdown_removed_file_is_all_deleted(): void
    {
        $html = (new DiffRenderer)->renderRichMarkdown("# Gone\n", null);

        $this->assertStringContainsString('<del', $html);
        $this->assertStringContainsString('Gone', $html);
    }

    public function test_rich_markdown_modified_file_shows_inline_ins_and_del(): void
    {
        $html = (new DiffRenderer)->renderRichMarkdown("hello world\n", "hello brave world\n");

        // caxy weaves <ins>/<del> into the rendered HTML.
        $this->assertStringContainsString('<ins', $html);
        $this->assertStringContainsString('brave', $html);
    }

    public function test_rich_markdown_guards_against_huge_files(): void
    {
        $huge = str_repeat("line\n", DiffRenderer::FULL_FILE_LINE_LIMIT + 1);

        $this->assertNull((new DiffRenderer)->renderRichMarkdown(null, $huge));
    }
}
