<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connect_agent_page_renders(): void
    {
        $user = User::factory()->create();
        $user->createToken('laptop');

        $this->actingAs($user)->get(route('agent-tokens.index'))
            ->assertOk()
            ->assertSee('Connect a coding agent')
            ->assertSee('laptop');
    }

    public function test_a_user_can_mint_a_token_and_sees_the_plaintext_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('agent-tokens.store'), ['name' => 'laptop']);

        $response->assertRedirect(route('agent-tokens.index'));
        $response->assertSessionHas('plain_token');
        $this->assertCount(1, $user->tokens()->get());
        $this->assertSame('laptop', $user->tokens()->sole()->name);
    }

    public function test_a_user_can_revoke_only_their_own_token(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerToken = $stranger->createToken('theirs')->accessToken;

        $mine = $user->createToken('mine')->accessToken;

        // Revoking someone else's token id does nothing (scoped to the user).
        $this->actingAs($user)->delete(route('agent-tokens.destroy', $strangerToken->id));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $strangerToken->id]);

        $this->actingAs($user)->delete(route('agent-tokens.destroy', $mine->id));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mine->id]);
    }

    public function test_the_mcp_endpoint_rejects_unauthenticated_requests(): void
    {
        // No token → the auth:sanctum guard bounces the request (guests are sent
        // to login); either way it never reaches a tool.
        $response = $this->post('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertContains($response->status(), [302, 401, 403]);
        $this->assertNotSame(200, $response->status());
    }
}
