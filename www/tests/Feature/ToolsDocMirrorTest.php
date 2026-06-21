<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Drift guard for the MCP tool family: every concrete tool in app/Mcp/Tools must
 * have a row in docs/classes/TOOLS.md, so the family doc can't silently fall
 * behind the code. Mirrors SchemaMirrorTest — discovers the real roster from the
 * filesystem and skips loudly where docs/ isn't mounted.
 */
class ToolsDocMirrorTest extends TestCase
{
    public function test_every_mcp_tool_is_documented(): void
    {
        $doc = $this->doc();

        $missing = array_values(array_filter(
            $this->toolNames(),
            fn (string $name) => ! str_contains($doc, $name)
        ));

        $this->assertSame([], $missing,
            'docs/classes/TOOLS.md is missing rows for: '.implode(', ', $missing));
    }

    /**
     * The real roster: every concrete class under app/Mcp/Tools (minus the
     * abstract base), named the way laravel/mcp derives it — snake_case of the
     * class without its "Tool" suffix (ClaimTaskTool → claim_task).
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        $names = [];
        foreach (glob(app_path('Mcp/Tools').'/*.php') as $file) {
            $class = basename($file, '.php');
            if ($class === 'LodestarTool') {
                continue;
            }
            $names[] = Str::snake(Str::replaceLast('Tool', '', $class));
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
