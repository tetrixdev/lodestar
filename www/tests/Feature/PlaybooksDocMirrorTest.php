<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Playbook;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strict mirror guard for the system-playbook family: the roster TABLE in
 * docs/classes/PLAYBOOKS.md must list EXACTLY the keys the SystemPlaybookSeeder
 * seeds at the system scope — no more, no less. Like SchemaMirrorTest, it
 * discovers the real roster (by running the seeder) and skips loudly where docs/
 * isn't mounted.
 *
 * "Strict" means the same upgrade ToolsDocMirrorTest got: it reads the roster
 * TABLE (the `| `key` | … |` rows), matches the key as the WHOLE first cell (not
 * a substring of prose), and asserts BOTH directions — every seeded key has a
 * row, AND every roster row maps to a seeded key (no orphan rows for removed
 * playbooks).
 */
class PlaybooksDocMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doc_table_exactly_mirrors_the_seeded_system_playbooks(): void
    {
        $doc = $this->doc();

        $this->seed(SystemPlaybookSeeder::class);

        $seeded = Playbook::query()
            ->where('scope', Playbook::SCOPE_SYSTEM)
            ->pluck('key')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($seeded, 'the seeder produced no system playbooks');

        $documented = $this->documentedKeys($doc);

        $missing = array_values(array_diff($seeded, $documented));
        $this->assertSame([], $missing,
            'docs/classes/PLAYBOOKS.md is missing a roster row for: '.implode(', ', $missing));

        $orphans = array_values(array_diff($documented, $seeded));
        $this->assertSame([], $orphans,
            'docs/classes/PLAYBOOKS.md has roster rows for keys no longer seeded: '.implode(', ', $orphans));
    }

    /**
     * The keys declared in the doc's roster table: the FIRST cell of any table
     * row whose first cell is a single `` `code` `` token — exactly, never a
     * substring of prose.
     *
     * @return list<string>
     */
    private function documentedKeys(string $doc): array
    {
        $keys = [];
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue; // not a table row
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            $first = $cells[0] ?? '';

            if (preg_match('/^`([a-z][a-z0-9_-]*)`$/', $first, $m)) {
                $keys[] = $m[1];
            }
        }
        sort($keys);

        return $keys;
    }

    private function doc(): string
    {
        $path = base_path('../docs/classes/PLAYBOOKS.md');
        if (! is_readable($path)) {
            $this->markTestSkipped("PLAYBOOKS.md not reachable at {$path}. Mount ./docs -> /var/docs so this guard can run.");
        }

        return (string) file_get_contents($path);
    }
}
