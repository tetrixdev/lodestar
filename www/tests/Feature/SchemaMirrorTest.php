<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The honesty guard: docs/DATA-MODEL.md MIRRORS the built schema, so it must not
 * silently drift.
 *
 * The REQUIRED source of truth is the "## Field reference" section: each
 * `### `table_name`` heading is a live table name, and the markdown table under
 * it lists every column in its first (`Field`) cell. This test asserts, for each
 * documented table, that those Field names match the live schema BOTH directions
 * (nothing missing from the doc, nothing phantom in the doc), and — for
 * completeness — that every live app table (minus the framework set) HAS a
 * documented field table, so a new table can't be silently undocumented.
 *
 * The Mermaid erDiagram is an optional second guard: if the block is present we
 * parse and check it with the same column-match logic; if it's absent we skip
 * just that part. The tables alone suffice.
 *
 * Deliberately ignored (documented as omitted in the doc's preamble):
 *  - `created_at` / `updated_at` timestamps;
 *  - `unsigned` qualifiers (Postgres has no unsigned ints) — we check column
 *    NAMES, not types, which is the honest, low-false-positive guard.
 *
 * docs/ lives at the repo root (sibling of www/), mounted into the dev container
 * at /var/docs once compose maps ./docs -> /var/docs. Where it isn't wired (a
 * bare prod image, a fresh clone with no mount) the test skips loudly rather
 * than redding the suite.
 */
class SchemaMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const IGNORED_COLUMNS = ['created_at', 'updated_at'];

    /**
     * Framework / scaffolding tables that are NOT documented and are excluded
     * from the completeness check (they're standard Laravel, not app schema).
     */
    private const IGNORED_TABLES = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /** Mermaid box name => real table name where it isn't just snake-plural. */
    private const DIAGRAM_TABLE_ALIASES = [
        'REVIEW_TASK' => 'review_task', // the pivot keeps the singular Laravel name
        'REVIEW_FILE_SECTION' => 'review_file_section', // file<->section pivot, singular
        'PROJECT_REPOSITORY' => 'project_repository', // project<->repo pivot, singular
        'TEAM_USER' => 'team_user', // team membership pivot, singular
        'PROJECT_USER' => 'project_user', // project membership pivot, singular
    ];

    public function test_field_reference_tables_mirror_the_live_schema(): void
    {
        $tables = $this->parseFieldReferenceTables();

        $this->assertNotEmpty(
            $tables,
            'Parsed no field tables from the "## Field reference" section of DATA-MODEL.md — '
            .'the parser or the doc shape changed. Every app table must have a `### `name`` heading '
            .'followed by a markdown table whose first column lists its fields.'
        );

        $problems = [];

        // 1. Every documented table matches the live schema, both directions.
        foreach ($tables as $table => $documentedColumns) {
            if (! Schema::hasTable($table)) {
                $problems[] = "Doc documents table `{$table}` which does not exist in the schema.";

                continue;
            }

            $problems = array_merge($problems, $this->columnMismatches($table, $documentedColumns));
        }

        // 2. Completeness: every live app table must have a documented field table.
        foreach ($this->liveAppTables() as $table) {
            if (! array_key_exists($table, $tables)) {
                $problems[] = "Live table `{$table}` has NO field table in the doc's \"## Field reference\" "
                    .'(add a `### `'.$table.'`` heading + table, or list it in IGNORED_TABLES if it is framework scaffolding).';
            }
        }

        $this->assertSame(
            [],
            $problems,
            "DATA-MODEL.md's field reference has drifted from the schema. Update the doc (or the migration) so they match:\n - "
                .implode("\n - ", $problems)
        );
    }

    public function test_er_diagram_when_present_mirrors_the_live_schema(): void
    {
        $boxes = $this->parseDiagramBoxes();

        if ($boxes === []) {
            $this->markTestSkipped('No `erDiagram` block found in DATA-MODEL.md — the field-reference guard is the required check.');
        }

        $problems = [];

        foreach ($boxes as $boxName => $documentedColumns) {
            $table = $this->diagramTableName($boxName);

            if (! Schema::hasTable($table)) {
                $problems[] = "Diagram documents box `{$boxName}` (=> `{$table}`) which does not exist in the schema.";

                continue;
            }

            $problems = array_merge($problems, $this->columnMismatches($table, $documentedColumns));
        }

        $this->assertSame(
            [],
            $problems,
            "DATA-MODEL.md's erDiagram has drifted from the schema. Update the diagram (or the migration) so they match:\n - "
                .implode("\n - ", $problems)
        );
    }

    /**
     * Compare a documented column list against the live schema, both directions.
     *
     * @param  list<string>  $documentedColumns
     * @return list<string> problem messages (empty when they match)
     */
    private function columnMismatches(string $table, array $documentedColumns): array
    {
        $problems = [];

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

        return $problems;
    }

    /** Live app tables (everything minus the framework scaffolding set). */
    private function liveAppTables(): array
    {
        $all = collect(Schema::getTables())
            ->pluck('name')
            ->all();

        return array_values(array_diff($all, self::IGNORED_TABLES));
    }

    /**
     * Parse the markdown field tables under "## Field reference". Each is a
     * `### `name`` heading followed by a markdown table; the column names are the
     * first-column (`Field`) cells of the body rows (header + separator skipped).
     *
     * @return array<string, list<string>> table name => column names
     */
    private function parseFieldReferenceTables(): array
    {
        $doc = $this->docContents();

        // Isolate the "## Field reference" section (up to the next `## ` heading).
        if (! preg_match('/^##\s+Field reference\s*$(.*?)(?=^##\s+\S|\z)/ms', $doc, $sectionMatch)) {
            return [];
        }
        $section = $sectionMatch[1];

        $tables = [];

        // Each block: `### `name`` heading, then the lines until the next heading.
        if (! preg_match_all('/^###\s+`([^`]+)`\s*$(.*?)(?=^###\s|\z)/ms', $section, $blocks, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($blocks as $block) {
            $table = trim($block[1]);
            $body = $block[2];

            $columns = [];
            $rowIndex = 0;
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                // A markdown table row starts with `|`.
                if ($line === '' || ! str_starts_with($line, '|')) {
                    continue;
                }

                $rowIndex++;
                // Row 1 is the header (| Field | ... |), row 2 is the |---| separator.
                if ($rowIndex <= 2) {
                    continue;
                }

                // First cell is the Field name.
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $field = $cells[0] ?? '';
                if ($field !== '') {
                    $columns[] = $field;
                }
            }

            if ($columns !== []) {
                $tables[$table] = $columns;
            }
        }

        return $tables;
    }

    /**
     * Extract `BOX_NAME { type col ... }` blocks from the Mermaid erDiagram in
     * the doc. Returns box name => list of column names. Empty when there is no
     * erDiagram block (the optional guard then skips).
     *
     * @return array<string, list<string>>
     */
    private function parseDiagramBoxes(): array
    {
        $doc = $this->docContents();

        if (! preg_match('/```mermaid\s*\nerDiagram(.*?)```/s', $doc, $m)) {
            return [];
        }
        $diagram = $m[1];

        $boxes = [];
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

    private function diagramTableName(string $boxName): string
    {
        if (isset(self::DIAGRAM_TABLE_ALIASES[$boxName])) {
            return self::DIAGRAM_TABLE_ALIASES[$boxName];
        }

        // Default: lower-snake, pluralize the final segment. WORK_SESSION => work_sessions.
        $parts = explode('_', strtolower($boxName));
        $last = array_pop($parts);
        $parts[] = Str::plural($last);

        return implode('_', $parts);
    }

    /**
     * Read DATA-MODEL.md, or skip loudly when it isn't mounted into the app.
     * docs/ lives at the repo root; compose maps ./docs -> /var/docs.
     */
    private function docContents(): string
    {
        $path = base_path('../docs/DATA-MODEL.md');
        if (! is_readable($path)) {
            $this->markTestSkipped(
                "DATA-MODEL.md not reachable at {$path}. Mount the repo docs/ into the app "
                .'(map ./docs -> /var/docs in compose) so this drift guard can run.'
            );
        }

        return (string) file_get_contents($path);
    }
}
