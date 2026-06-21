<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Embedding;
use App\Models\Project;
use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\Queue;
use Tests\PgvectorTestCase;
use Tests\Support\FakeEmbeddingProvider;

/**
 * Embed/forget ONE object end-to-end against real pgvector, provider mocked.
 * Proves: a vector is stored with the denormalised tenant filter; unchanged
 * text is hash-skipped (no second API call); a forget deletes the row; and a
 * missing/invalid key fails safe (no row, no throw).
 */
class EmbeddingServiceTest extends PgvectorTestCase
{
    private function fakeProvider(bool $configured = true): FakeEmbeddingProvider
    {
        // Stop the model-event auto-dispatch (sync queue) so only the explicit
        // service calls under test run.
        Queue::fake();

        $fake = new FakeEmbeddingProvider(configured: $configured);
        $this->app->instance(EmbeddingProvider::class, $fake);

        return $fake;
    }

    public function test_embed_stores_a_vector_with_the_tenant_filter(): void
    {
        $fake = $this->fakeProvider();
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket', 'primary_goal' => 'reach orbit']);

        $service = $this->app->make(EmbeddingService::class);
        $this->assertTrue($service->embed($project));

        $row = Embedding::query()
            ->where('embeddable_type', $project->getMorphClass())
            ->where('embeddable_id', $project->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($project->id, (int) $row->project_id);
        $this->assertSame($user->id, (int) $row->owner_user_id);
        $this->assertFalse($row->is_system);
        $this->assertSame('fake-embedding-model', $row->model);
        // The embedded text is the project's name + goal, joined.
        $this->assertSame("Rocket\nreach orbit", $fake->embedded[0]);
    }

    public function test_unchanged_text_is_hash_skipped(): void
    {
        $fake = $this->fakeProvider();
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $service = $this->app->make(EmbeddingService::class);
        $service->embed($project);
        $callsAfterFirst = $fake->calls;

        // Re-embed with identical text — no new provider call, no second row.
        $this->assertFalse($service->embed($project->fresh()));
        $this->assertSame($callsAfterFirst, $fake->calls);
        $this->assertSame(1, Embedding::query()->count());
    }

    public function test_forget_deletes_the_vector(): void
    {
        $this->fakeProvider();
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $service = $this->app->make(EmbeddingService::class);
        $service->embed($project);
        $this->assertSame(1, Embedding::query()->count());

        $service->forget(Project::class, $project->id);
        $this->assertSame(0, Embedding::query()->count());
    }

    public function test_missing_key_fails_safe_with_no_row_and_no_throw(): void
    {
        $this->fakeProvider(configured: false);
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $service = $this->app->make(EmbeddingService::class);
        $this->assertFalse($service->enabled());
        $this->assertFalse($service->embed($project));
        $this->assertSame(0, Embedding::query()->count());
    }
}
