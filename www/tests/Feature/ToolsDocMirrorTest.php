<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * Strict mirror guard for the MCP tool family: docs/classes/TOOLS.md must list
 * EXACTLY the tools registered on LodestarServer — no more, no less. Like
 * SchemaMirrorTest, it discovers the real roster (here, the registry) and skips
 * loudly where docs/ isn't mounted.
 *
 * "Strict" means two things the old substring check did not:
 *  - it reads the tool TABLE (the `| `name` | … |` rows), not free prose, and
 *    matches the name as the WHOLE first cell — never as a substring of prose;
 *  - it asserts BOTH directions: every registered tool has exactly one doc row,
 *    AND every doc row maps to a registered tool (no orphan rows for tools that
 *    have since been deleted).
 */
class ToolsDocMirrorTest extends TestCase
{
    public function test_doc_table_exactly_mirrors_the_registered_tools(): void
    {
        $registered = $this->registeredToolNames();
        $documented = $this->documentedToolNames($this->doc());

        $missing = array_values(array_diff($registered, $documented));
        $this->assertSame([], $missing,
            'docs/classes/TOOLS.md is missing a table row for: '.implode(', ', $missing));

        $orphans = array_values(array_diff($documented, $registered));
        $this->assertSame([], $orphans,
            'docs/classes/TOOLS.md has table rows for tools no longer registered: '.implode(', ', $orphans));

        // Each tool appears in exactly one row (no duplicate name cells).
        $dupes = array_values(array_keys(array_filter(
            array_count_values($documented),
            fn (int $count) => $count > 1
        )));
        $this->assertSame([], $dupes,
            'docs/classes/TOOLS.md has duplicate rows for: '.implode(', ', $dupes));
    }

    /**
     * The real roster: the snake_case MCP name of every tool registered on
     * LodestarServer::$tools — the registry, not the filesystem, so an
     * un-registered (dead) tool file can't satisfy the guard.
     *
     * @return list<string>
     */
    private function registeredToolNames(): array
    {
        $defaults = (new ReflectionClass(LodestarServer::class))->getDefaultProperties();

        /** @var list<class-string> $classes */
        $classes = $defaults['tools'] ?? [];

        $names = array_map(
            fn (string $class) => Str::snake(Str::replaceLast('Tool', '', class_basename($class))),
            $classes
        );
        sort($names);

        return $names;
    }

    /**
     * The tool names declared in the doc's markdown tables: the FIRST cell of any
     * table row whose first cell is a single `` `code` `` token (the tool-name
     * cell), exactly — never a substring of prose or a sentence.
     *
     * @return list<string>
     */
    private function documentedToolNames(string $doc): array
    {
        $names = [];
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue; // not a table row
            }

            // First cell, trimmed of the leading/trailing pipes.
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $first = $cells[0] ?? '';

            // A tool-name cell is EXACTLY a backtick-wrapped snake_case token.
            if (preg_match('/^`([a-z][a-z0-9_]*)`$/', $first, $m)) {
                $names[] = $m[1];
            }
        }
        sort($names);

        return $names;
    }

    private function doc(): string
    {
        $path = base_path('../docs/classes/TOOLS.md');
        if (! is_readable($path)) {
            $this->markTestSkipped("TOOLS.md not reachable at {$path}. Mount ./docs -> /var/docs so this guard can run.");
        }

        return (string) file_get_contents($path);
    }
}
