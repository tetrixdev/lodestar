<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\UpsertTaskTool;
use App\Models\Playbook;
use App\Support\TaskSpec;
use Database\Seeders\SystemPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Tests\TestCase;

/**
 * The body/plan/summary format is defined ONCE in App\Support\TaskSpec, and both
 * the upsert_task tool descriptions and the seeded `plan` / `develop` playbooks
 * pull from it. This guard fails if either side stops referencing the single
 * source (the drift the format used to suffer).
 */
class TaskSpecSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_task_descriptions_use_the_task_spec(): void
    {
        $schema = (new UpsertTaskTool)->schema(new JsonSchemaTypeFactory);

        $descriptions = array_map(
            fn ($field) => $field->toArray()['description'] ?? '',
            $schema,
        );
        $blob = implode("\n", $descriptions);

        $this->assertStringContainsString(TaskSpec::BODY, $blob);
        $this->assertStringContainsString(TaskSpec::BODY_SUMMARY, $blob);
        $this->assertStringContainsString(TaskSpec::PLAN_SUMMARY, $blob);
        // The plan description embeds the architecture rule.
        $this->assertStringContainsString(TaskSpec::ARCHITECTURE_RULE, $blob);
    }

    public function test_plan_and_develop_playbooks_carry_the_task_spec(): void
    {
        $this->seed(SystemPlaybookSeeder::class);

        foreach (['plan', 'develop'] as $key) {
            $body = Playbook::query()
                ->where('scope', Playbook::SCOPE_SYSTEM)
                ->where('key', $key)
                ->first()
                ->activeVersion()
                ->first()
                ->body;

            $this->assertStringNotContainsString('{{TASK_SPEC}}', $body,
                "the {$key} playbook still has an un-substituted TASK_SPEC marker");
            $this->assertStringContainsString(TaskSpec::ARCHITECTURE_RULE, $body,
                "the {$key} playbook does not carry the single-sourced architecture rule");
            $this->assertStringContainsString(TaskSpec::BODY_SUMMARY, $body);
        }
    }
}
