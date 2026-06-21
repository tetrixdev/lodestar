<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Embedding;
use App\Models\Project;
use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use Illuminate\Support\Facades\Queue;
use Tests\PgvectorTestCase;
use Tests\Support\FakeEmbeddingProvider;

/**
 * The reconciler (lodestar:embed-sync), provider mocked, against real pgvector:
 * it embeds new objects, skips unchanged ones (no extra API call), and drops
 * embeddings whose object has vanished. Also: a missing/invalid key makes it a
 * fail-safe no-op.
 */
class EmbedSyncTest extends PgvectorTestCase
{
    private function fake(bool $configured = true, bool $valid = true): FakeEmbeddingProvider
    {
        Queue::fake(); // suppress the live model-event jobs; the command does the work
        $fake = new FakeEmbeddingProvider(configured: $configured, valid: $valid);
        $this->app->instance(EmbeddingProvider::class, $fake);

        return $fake;
    }

    public function test_embeds_new_objects(): void
    {
        $fake = $this->fake();
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);
        $user->projects()->create(['name' => 'Probe', 'slug' => 'probe']);

        $this->artisan('lodestar:embed-sync')->assertSuccessful();

        $this->assertSame(2, Embedding::query()->where('embeddable_type', (new Project)->getMorphClass())->count());
    }

    public function test_skips_unchanged_objects_on_a_second_run(): void
    {
        $fake = $this->fake();
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $this->artisan('lodestar:embed-sync')->assertSuccessful();
        $callsAfterFirst = $fake->calls;

        // Nothing changed — the second run must not call the provider again.
        $this->artisan('lodestar:embed-sync')->assertSuccessful();
        $this->assertSame($callsAfterFirst, $fake->calls);
    }

    public function test_deletes_orphaned_embeddings(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $this->artisan('lodestar:embed-sync')->assertSuccessful();
        $this->assertSame(1, Embedding::query()->count());

        // Force-delete the object directly so no ForgetObject runs (Queue faked):
        // the embedding is now an orphan the reconciler must drop.
        Project::query()->whereKey($project->id)->delete();

        $this->artisan('lodestar:embed-sync')->assertSuccessful();
        $this->assertSame(0, Embedding::query()->count());
    }

    public function test_invalid_key_is_a_fail_safe_no_op(): void
    {
        $this->fake(configured: true, valid: false);
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $this->artisan('lodestar:embed-sync')->assertSuccessful();
        $this->assertSame(0, Embedding::query()->count());
    }
}
