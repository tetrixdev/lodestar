<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_lists_tools_with_params_and_example_output(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mcp.reference'))
            ->assertOk()
            ->assertSee('The agent loop')        // a group heading
            ->assertSee('claim_task')            // a tool name
            ->assertSee('Atomically claim')      // its description
            ->assertSee('task_id')               // a parameter (from the schema)
            ->assertSee('Returns')               // example-output label
            ->assertSee('propose_playbook_change'); // the P3 tool is present
    }
}
