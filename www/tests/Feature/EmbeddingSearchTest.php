<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Playbook;
use App\Models\Project;
use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingSearch;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\Queue;
use Tests\PgvectorTestCase;
use Tests\Support\FakeEmbeddingProvider;

/**
 * The search read side, against real pgvector with a mocked provider: results
 * are ACCESS-FILTERED to the caller (one user never sees another's project),
 * system playbooks are visible to everyone, and a missing key degrades to an
 * empty result rather than throwing.
 */
class EmbeddingSearchTest extends PgvectorTestCase
{
    private function bindProvider(bool $configured = true): FakeEmbeddingProvider
    {
        Queue::fake();
        $fake = new FakeEmbeddingProvider(configured: $configured);
        $this->app->instance(EmbeddingProvider::class, $fake);

        return $fake;
    }

    private function embed(object $object): void
    {
        $this->app->make(EmbeddingService::class)->embed($object);
    }

    public function test_search_returns_only_the_callers_accessible_objects(): void
    {
        $this->bindProvider();

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceProject = $alice->projects()->create(['name' => 'Alice rocket', 'slug' => 'alice-rocket']);
        $bobProject = $bob->projects()->create(['name' => 'Bob rocket', 'slug' => 'bob-rocket']);
        $this->embed($aliceProject);
        $this->embed($bobProject);

        $search = $this->app->make(EmbeddingSearch::class);

        $aliceHits = collect($search->search($alice, 'rocket', limit: 50));
        $this->assertTrue($aliceHits->contains(fn ($h) => $h['embeddable_id'] === $aliceProject->id));
        $this->assertFalse($aliceHits->contains(fn ($h) => $h['embeddable_id'] === $bobProject->id),
            'Alice must not see Bob\'s project.');

        $bobHits = collect($search->search($bob, 'rocket', limit: 50));
        $this->assertTrue($bobHits->contains(fn ($h) => $h['embeddable_id'] === $bobProject->id));
        $this->assertFalse($bobHits->contains(fn ($h) => $h['embeddable_id'] === $aliceProject->id));
    }

    public function test_system_playbooks_are_visible_to_every_user(): void
    {
        $this->bindProvider();

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // A system playbook with an active version (the version is the embeddable).
        $slot = Playbook::create(['scope' => Playbook::SCOPE_SYSTEM, 'key' => 'plan', 'title' => 'Plan']);
        $version = $slot->versions()->create([
            'version' => 1, 'title' => 'Plan playbook', 'mode' => Playbook::MODE_APPEND,
            'body' => 'How to plan a feature end to end', 'status' => 'active',
        ]);
        $this->embed($version);

        $search = $this->app->make(EmbeddingSearch::class);

        foreach ([$alice, $bob] as $user) {
            $hits = collect($search->search($user, 'plan a feature', limit: 50));
            $this->assertTrue(
                $hits->contains(fn ($h) => $h['embeddable_id'] === $version->id),
                'Every user should see the system playbook.'
            );
        }
    }

    public function test_missing_key_degrades_to_empty_without_throwing(): void
    {
        $this->bindProvider(configured: false);
        $user = User::factory()->create();

        $search = $this->app->make(EmbeddingSearch::class);
        $this->assertFalse($search->enabled());
        $this->assertSame([], $search->search($user, 'anything'));
    }
}
