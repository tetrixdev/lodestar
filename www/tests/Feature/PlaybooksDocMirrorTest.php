<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Playbook;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drift guard for the system-playbook family: every key the SystemPlaybookSeeder
 * seeds at the system scope must have a row in docs/classes/PLAYBOOKS.md. Mirrors
 * SchemaMirrorTest — discovers the real roster (by running the seeder) and skips
 * loudly where docs/ isn't mounted.
 */
class PlaybooksDocMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_system_playbook_is_documented(): void
    {
        $doc = $this->doc();

        $this->seed(SystemPlaybookSeeder::class);

        $keys = Playbook::query()
            ->where('scope', Playbook::SCOPE_SYSTEM)
            ->pluck('key')
            ->unique()
            ->sort()
            ->values();

        $this->assertNotEmpty($keys, 'the seeder produced no system playbooks');

        $missing = $keys
            ->reject(fn (string $key) => str_contains($doc, '`'.$key.'`'))
            ->values()
            ->all();

        $this->assertSame([], $missing,
            'docs/classes/PLAYBOOKS.md is missing rows for: '.implode(', ', $missing));
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
