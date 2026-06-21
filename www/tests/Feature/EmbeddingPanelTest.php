<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * The AI & Embeddings status panel on Settings + the /search page render and
 * reflect configured state. (No vector type needed — these assert HTML, so they
 * run on the default sqlite connection; the key is mocked, never real.)
 */
class EmbeddingPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_shows_no_key_when_unconfigured(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: false));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('AI &amp; Embeddings', false)
            ->assertSee('No key')
            ->assertSee('projects'); // a per-type count row label
    }

    public function test_panel_shows_configured_and_per_type_counts(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: true));
        $user = User::factory()->create();
        $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        $this->actingAs($user)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Key configured')
            ->assertSee('Test key')
            ->assertSee('Re-sync');
    }

    public function test_test_key_action_reports_validity(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: true, valid: true));
        $user = User::factory()->create();

        // Come from the settings page so back() returns there with the flash.
        $this->actingAs($user)
            ->from(route('settings.index'))
            ->post(route('settings.embeddings.test-key'))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('embeddings_status', fn ($s) => $s['ok'] === true);

        // The flashed status renders on the panel.
        $this->actingAs($user)
            ->withSession(['embeddings_status' => ['ok' => true, 'message' => 'Key validated — OpenAI accepted it.']])
            ->get(route('settings.index'))
            ->assertSee('Key validated');
    }

    public function test_search_page_renders_and_warns_when_unconfigured(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: false));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('search', ['q' => 'anything']))
            ->assertOk()
            ->assertSee('not configured');
    }
}
