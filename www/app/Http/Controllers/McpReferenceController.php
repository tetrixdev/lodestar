<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mcp\Servers\LodestarServer;
use Illuminate\View\View;
use ReflectionClass;

/**
 * A human-readable reference for the Lodestar MCP surface: every tool grouped,
 * with its parameters (auto-generated from each tool's own schema, so it can't
 * drift) and an example of what it returns. This is the map an operator reads to
 * understand how an agent drives the board.
 */
class McpReferenceController extends Controller
{
    /** Tool name => display group. Anything unlisted falls under "Other". */
    private const GROUPS = [
        'list_projects' => 'Projects & board',
        'upsert_project' => 'Projects & board',
        'upsert_task' => 'Projects & board',
        'upsert_session' => 'Projects & board',
        'link_repository' => 'Repositories',
        'unlink_repository' => 'Repositories',
        'create_review' => 'Reviews',
        'upsert_review_section' => 'Reviews',
        'upsert_plan_review_section' => 'Reviews',
        'add_finding' => 'Reviews',
        'get_review' => 'Reviews',
        'claim_task' => 'The agent loop',
        'get_playbook' => 'The agent loop',
        'propose_playbook_change' => 'The agent loop',
        'advance_task' => 'The agent loop',
        'report' => 'The agent loop',
    ];

    /** The order groups are shown in. */
    private const GROUP_ORDER = ['The agent loop', 'Projects & board', 'Reviews', 'Repositories', 'Other'];

    /** A representative return payload per tool (mirrors the handlers' Response::json). */
    private const EXAMPLES = [
        'list_projects' => '{ "projects": [ { "id": 1, "name": "Lodestar", "slug": "lodestar", "repositories": [...] } ], "github_connections": [...] }',
        'upsert_project' => '{ "id": 1, "name": "Lodestar", "slug": "lodestar", "created": false }',
        'upsert_task' => '{ "id": 53, "title": "Playbook governance", "status": "ready_for_planning", "created": false }',
        'upsert_session' => '{ "id": 12, "title": "Built the board", "created": true }',
        'link_repository' => '{ "linked": "jfbauer/lodestar", "default_branch": "main", "connection": "jfbauer", "project_repos": ["jfbauer/lodestar"] }',
        'unlink_repository' => '{ "unlinked": "jfbauer/lodestar", "project_repos": [] }',
        'create_review' => '{ "id": 7, "url": "https://.../reviews/7", "repository": "jfbauer/lodestar", "linked_tasks": 1, "files": 9, "next": "Add sections..." }',
        'upsert_review_section' => '{ "id": 3, "review_id": 7, "position": 1, "created": true, "coverage": { "covered": 4, "total": 9 } }',
        'upsert_plan_review_section' => '{ "id": 3, "task_id": 74, "position": 1, "created": true, "sections": 4 }',
        'add_finding' => '{ "id": 5, "section_id": 3, "severity": "major", "finding_count": 2 }',
        'get_review' => '{ "id": 7, "title": "...", "status": "in_review", "coverage": {...}, "files": [...], "tasks": [...] }',
        'claim_task' => '{ "claimed": true, "task": { "id": 53, "status": "developing", "phase": "develop", "rework_notes": null }, "next": "Call get_playbook..." }'."\n".'// or: { "claimed": false, "message": "No task available to claim." }',
        'get_playbook' => '// phase key (composed): { "key": "develop", "composed": true, "body": "...", "layers": [ { "scope": "system", ... } ] }'."\n".'// named key: { "key": "db-recipe", "composed": false, "scope": "project", "version": 2, "title": "...", "body": "..." }',
        'propose_playbook_change' => '{ "playbook_id": 4, "version_id": 9, "version": 3, "status": "proposed", "note": "Recorded as a proposal — a human approver must make it live." }',
        'advance_task' => '{ "id": 53, "status": "plan_review", "allowed_next": ["ready_for_dev", "ready_for_planning", "cancelled"] }',
        'report' => '{ "id": 12, "project_id": 1, "logged": true }',
    ];

    public function index(): View
    {
        /** @var array<int, class-string> $toolClasses */
        $toolClasses = (new ReflectionClass(LodestarServer::class))->getDefaultProperties()['tools'] ?? [];

        $tools = collect($toolClasses)
            ->map(fn (string $class) => app($class)->toArray())
            ->map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'params' => $this->params($t['inputSchema'] ?? []),
                'example' => self::EXAMPLES[$t['name']] ?? null,
                'group' => self::GROUPS[$t['name']] ?? 'Other',
            ])
            ->groupBy('group')
            ->sortBy(fn ($_, $group) => array_search($group, self::GROUP_ORDER, true));

        return view('settings.mcp', ['groups' => $tools]);
    }

    /**
     * Flatten a tool's JSON-Schema inputSchema into rows for the table.
     *
     * @param  array<string, mixed>  $schema
     * @return list<array{name: string, type: string, required: bool, enum: ?string, description: string}>
     */
    private function params(array $schema): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        $rows = [];
        foreach ($properties as $name => $spec) {
            $spec = (array) $spec;
            $rows[] = [
                'name' => $name,
                'type' => (string) ($spec['type'] ?? 'string'),
                'required' => in_array($name, $required, true),
                'enum' => isset($spec['enum']) ? implode(' | ', (array) $spec['enum']) : null,
                'description' => (string) ($spec['description'] ?? ''),
            ];
        }

        return $rows;
    }
}
