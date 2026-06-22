<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Markdown;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Markdown rendering surface (task #100 part E): markdown tables become real
 * <table> elements, the prose container carries the mobile horizontal-scroll
 * utility (readable at ~360px, unchanged on desktop), and a summary renders as
 * markdown rather than a flat blob.
 */
class MarkdownTableTest extends TestCase
{
    public function test_markdown_table_renders_to_an_html_table(): void
    {
        $html = Markdown::render("| A | B |\n|---|---|\n| 1 | 2 |\n");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>A</th>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
    }

    public function test_markdown_component_carries_the_mobile_table_scroll_utility(): void
    {
        $html = Blade::render('<x-markdown :content="$content" />', [
            'content' => "| A | B |\n|---|---|\n| 1 | 2 |\n",
        ]);

        // Mobile: table is a horizontally-scrollable block; desktop restores table layout.
        $this->assertStringContainsString('[&_table]:overflow-x-auto', $html);
        $this->assertStringContainsString('sm:[&_table]:table', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_summary_renders_markdown_not_a_blob(): void
    {
        $html = Blade::render('<x-markdown :content="$content" />', [
            'content' => 'A **bold** word and a [link](https://example.com).',
        ]);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
    }

    /**
     * MCP-authored content (finding detail / section comment) that arrives with a
     * LITERAL backslash-n (the visible text `\n`, not a real newline) must render
     * as real paragraphs + a bullet list, not the raw escape sequence.
     */
    public function test_literal_backslash_n_becomes_real_breaks_and_bullets(): void
    {
        $html = Markdown::render("a\\n\\n- b\\n- c");

        // Paragraph break + a real list with two items — and no leftover `\n` text.
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>b</li>', $html);
        $this->assertStringContainsString('<li>c</li>', $html);
        $this->assertStringNotContainsString('\\n', $html);
    }

    /** A literal `\n` INSIDE a code span is left intact (it's a code sample). */
    public function test_literal_backslash_n_inside_code_is_preserved(): void
    {
        $html = Markdown::render('use `split("\\n")` to split');

        $this->assertStringContainsString('split(&quot;\\n&quot;)', $html);
    }
}
