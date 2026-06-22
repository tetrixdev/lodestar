<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\LodestarServer;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Tests\TestCase;

/**
 * The honesty guard for the MCP-tool family doc: docs/classes/TOOLS.md MIRRORS
 * the registered tools on LodestarServer, so it must not silently drift.
 *
 * Mirrors SchemaMirrorTest's doctrine: parse the doc's roster table (each row's
 * `name` and `class` cells), then assert it matches LodestarServer::$tools BOTH
 * directions — nothing registered is missing from the doc, nothing in the doc is
 * a phantom that isn't registered. Both the tool's #[Name] and its short class
 * name must line up.
 *
 * docs/ lives at the repo root (sibling of www/), mounted into the dev container
 * at /var/docs once compose maps ./docs -> /var/docs. Where it isn't wired (a
 * bare prod image, a fresh clone with no mount) the test skips loudly rather
 * than redding the suite.
 */
class ToolsDocMirrorTest extends TestCase
{
    public function test_tools_doc_mirrors_the_registered_tools(): void
    {
        $documented = $this->parseRosterTable();

        $this->assertNotEmpty(
            $documented,
            'Parsed no rows from the "## The roster" table of docs/classes/TOOLS.md — '
            .'the parser or the doc shape changed. Each tool must have a `| `name` | `Class` | group | purpose |` row.'
        );

        // The live registration: tool short-class-name => #[Name] value.
        $registered = [];
        foreach ($this->registeredTools() as $class) {
            $registered[$this->shortName($class)] = $this->toolName($class);
        }

        $problems = [];

        // 1. Every registered tool is documented (name + class match).
        foreach ($registered as $shortClass => $name) {
            if (! array_key_exists($shortClass, $documented)) {
                $problems[] = "Registered tool `{$shortClass}` (name `{$name}`) is MISSING from docs/classes/TOOLS.md.";

                continue;
            }
            if ($documented[$shortClass] !== $name) {
                $problems[] = "Tool `{$shortClass}` is documented with name `{$documented[$shortClass]}` but registers as `{$name}`.";
            }
        }

        // 2. No phantom rows: every documented tool is actually registered.
        foreach ($documented as $shortClass => $name) {
            if (! array_key_exists($shortClass, $registered)) {
                $problems[] = "docs/classes/TOOLS.md documents tool `{$shortClass}` (name `{$name}`) which is NOT registered on LodestarServer.";
            }
        }

        $this->assertSame(
            [],
            $problems,
            "docs/classes/TOOLS.md has drifted from LodestarServer::\$tools. Update the doc (or the registration) so they match:\n - "
                .implode("\n - ", $problems)
        );
    }

    /**
     * Parse the "## The roster" markdown table. Returns short-class-name => name,
     * read from the `class` and `name` cells (both stripped of backticks).
     *
     * @return array<string, string>
     */
    private function parseRosterTable(): array
    {
        $doc = $this->docContents();

        if (! preg_match('/^##\s+The roster\s*$(.*?)(?=^##\s+\S|\z)/ms', $doc, $sectionMatch)) {
            return [];
        }
        $section = $sectionMatch[1];

        $rows = [];
        $rowIndex = 0;
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }

            $rowIndex++;
            // Row 1 is the header, row 2 the |---| separator.
            if ($rowIndex <= 2) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 2) {
                continue;
            }

            $name = trim($cells[0], '` ');
            $class = trim($cells[1], '` ');
            if ($name !== '' && $class !== '') {
                $rows[$class] = $name;
            }
        }

        return $rows;
    }

    /**
     * The tools registered on LodestarServer (the protected $tools array).
     *
     * @return list<class-string<Tool>>
     */
    private function registeredTools(): array
    {
        // Read the protected $tools default without constructing the Server (its
        // constructor needs a Transport we don't have in a unit context).
        return (new ReflectionClass(LodestarServer::class))->getDefaultProperties()['tools'];
    }

    /** The short (unqualified) class name. */
    private function shortName(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }

    /** The tool's registered name (its #[Name] value, resolved via the tool itself). */
    private function toolName(string $class): string
    {
        /** @var Tool $tool */
        $tool = new $class;

        return $tool->name();
    }

    /** Read TOOLS.md, or skip loudly when it isn't mounted into the app. */
    private function docContents(): string
    {
        $path = base_path('../docs/classes/TOOLS.md');
        if (! is_readable($path)) {
            $this->markTestSkipped(
                "docs/classes/TOOLS.md not reachable at {$path}. Mount the repo docs/ into the app "
                .'(map ./docs -> /var/docs in compose) so this drift guard can run.'
            );
        }

        return (string) file_get_contents($path);
    }
}
