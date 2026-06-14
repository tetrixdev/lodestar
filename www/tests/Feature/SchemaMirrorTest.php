<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The honesty guard: docs/DATA-MODEL.md MIRRORS the built schema, so it must not
 * silently drift. This parses every table box in the doc's Mermaid ER diagram and
 * asserts its columns match the live schema, column-for-column.
 *
 * Deliberately ignored (documented as omitted in the diagram's preamble):
 *  - `created_at` / `updated_at` timestamps;
 *  - `unsigned` qualifiers (Postgres has no unsigned ints) — we check column
 *    NAMES, not types, which is the honest, low-false-positive guard.
 *
 * docs/ lives at the repo root, NOT inside www/, and the dev/CI container only
 * mounts www/ at /var/www — so the doc is unreachable in-container today. When
 * that's the case the test skips loudly rather than redding the suite; mount
 * ./docs into the app (e.g. -> /var/docs) to make this guard actually run.
 */
class SchemaMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const IGNORED_COLUMNS = ['created_at', 'updated_at'];

    /** Mermaid box name => real table name where it isn't just snake-plural. */
    private const TABLE_ALIASES = [
        'REVIEW_TASK' => 'review_task', // the pivot keeps the singular Laravel name
        'REVIEW_FILE_SECTION' => 'review_file_section', // file<->section pivot, singular
    ];

    public function test_data_model_diagram_matches_the_live_schema(): void
    {
        $boxes = $this->parseDiagramBoxes();

        $this->assertNotEmpty($boxes, 'Parsed no table boxes from DATA-MODEL.md — the parser or doc shape changed.');

        $problems = [];

        foreach ($boxes as $boxName => $documentedColumns) {
            $table = $this->tableName($boxName);

            if (! Schema::hasTable($table)) {
                $problems[] = "Doc documents table `{$boxName}` (=> `{$table}`) which does not exist in the schema.";

                continue;
            }

            $actual = array_diff(Schema::getColumnListing($table), self::IGNORED_COLUMNS);
            $documented = array_diff($documentedColumns, self::IGNORED_COLUMNS);

            $missingFromDoc = array_diff($actual, $documented);
            $phantomInDoc = array_diff($documented, $actual);

            if ($missingFromDoc !== []) {
                $problems[] = "`{$table}`: columns in the schema but MISSING from the doc: ".implode(', ', $missingFromDoc);
            }
            if ($phantomInDoc !== []) {
                $problems[] = "`{$table}`: columns in the doc but NOT in the schema: ".implode(', ', $phantomInDoc);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "DATA-MODEL.md has drifted from the schema. Update the doc (or the migration) so they match:\n - "
                .implode("\n - ", $problems)
        );
    }

    /**
     * Extract `BOX_NAME { type col ... }` blocks from the Mermaid erDiagram in
     * the doc. Returns box name => list of column names.
     *
     * @return array<string, list<string>>
     */
    private function parseDiagramBoxes(): array
    {
        // docs/ lives at the repo root (sibling of www/), mounted into the dev
        // container at /var/docs once compose maps ./docs -> /var/docs. Where it
        // isn't wired (today's container, a bare prod image), skip loudly rather
        // than red the suite.
        $path = base_path('../docs/DATA-MODEL.md');
        if (! is_readable($path)) {
            $this->markTestSkipped(
                "DATA-MODEL.md not reachable at {$path}. Mount the repo docs/ into the app "
                ."(map ./docs -> /var/docs in compose) so this drift guard can run."
            );
        }
        $doc = (string) file_get_contents($path);

        // Isolate the mermaid erDiagram fenced block.
        if (! preg_match('/```mermaid\s*\nerDiagram(.*?)```/s', $doc, $m)) {
            return [];
        }
        $diagram = $m[1];

        $boxes = [];
        // A box: NAME {  ... lines ...  }
        if (! preg_match_all('/^\s{4}([A-Z_]+)\s*\{\n(.*?)^\s{4}\}/ms', $diagram, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $boxName = $match[1];
            $body = $match[2];
            $columns = [];

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Each column line is: `<type> <name> [PK|FK|UK] ["comment"]`.
                // The column name is the second whitespace-delimited token.
                $tokens = preg_split('/\s+/', $line) ?: [];
                if (count($tokens) < 2) {
                    continue;
                }
                $columns[] = $tokens[1];
            }

            $boxes[$boxName] = $columns;
        }

        return $boxes;
    }

    private function tableName(string $boxName): string
    {
        if (isset(self::TABLE_ALIASES[$boxName])) {
            return self::TABLE_ALIASES[$boxName];
        }

        // Default: lower-snake, pluralize the final segment. WORK_SESSION => work_sessions.
        $parts = explode('_', strtolower($boxName));
        $last = array_pop($parts);
        $parts[] = Str::plural($last);

        return implode('_', $parts);
    }
}
