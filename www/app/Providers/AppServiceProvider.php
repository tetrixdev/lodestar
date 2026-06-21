<?php

namespace App\Providers;

use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\OpenAiEmbeddingProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The embedding boundary: OpenAI in prod; tests bind a fake so no real
        // API call (or key) is needed.
        $this->app->bind(EmbeddingProvider::class, OpenAiEmbeddingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
