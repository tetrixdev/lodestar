<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase
{
    public function test_mermaid_fence_becomes_a_diagram_container_with_decoded_source(): void
    {
        // The fence body contains characters the markdown engine HTML-escapes
        // (`--`, `>`); mermaid needs the raw text, so the container must decode it.
        $md = "intro\n\n```mermaid\ngraph TD\n  A --> B\n```\n";

        $html = Markdown::render($md);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        // Not left as a code block.
        $this->assertStringNotContainsString('language-mermaid', $html);
        // Source is decoded: the literal arrow, not the HTML entity.
        $this->assertStringContainsString('A --> B', $html);
        $this->assertStringNotContainsString('A --&gt; B', $html);
    }

    public function test_non_mermaid_code_fence_is_left_as_a_code_block(): void
    {
        $html = Markdown::render("```php\necho 'hi';\n```\n");

        $this->assertStringContainsString('language-php', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
    }
}
