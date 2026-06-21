<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\LodestarServer;
use App\Mcp\Tools\Search;
use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\Queue;
use Tests\PgvectorTestCase;
use Tests\Support\FakeEmbeddingProvider;

/**
 * The `search` MCP tool end-to-end (pgvector, provider mocked): a caller gets
 * back their own objects as {type,id,title,snippet,url}, never another user's;
 * and with no key it returns an empty result + a note rather than an error.
 */
class SearchToolTest extends PgvectorTestCase
{
    private function bindProvider(bool $configured = true): FakeEmbeddingProvider
    {
        Queue::fake();
        $fake = new FakeEmbeddingProvider(configured: $configured);
        $this->app->instance(EmbeddingProvider::class, $fake);

        return $fake;
    }

    public function test_tool_returns_callers_objects_and_hides_others(): void
    {
        $this->bindProvider();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceProject = $alice->projects()->create(['name' => 'Alice rocket', 'slug' => 'alice-rocket']);
        $bobProject = $bob->projects()->create(['name' => 'Bob rocket', 'slug' => 'bob-rocket']);

        $service = $this->app->make(EmbeddingService::class);
        $service->embed($aliceProject);
        $service->embed($bobProject);

        LodestarServer::actingAs($alice)
            ->tool(Search::class, ['query' => 'rocket', 'limit' => 50])
            ->assertOk()
            ->assertSee('Alice rocket')
            ->assertDontSee('Bob rocket');
    }

    public function test_tool_degrades_when_unconfigured(): void
    {
        $this->bindProvider(configured: false);
        $user = User::factory()->create();

        LodestarServer::actingAs($user)
            ->tool(Search::class, ['query' => 'rocket'])
            ->assertOk()
            ->assertSee('not configured');
    }
}
