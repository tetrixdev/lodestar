<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The OpenAI embeddings boundary. Talks to /v1/embeddings (batched) and
 * /v1/models (the key sanity check). Operator opt-in: with no key it reports
 * `isConfigured() === false` and every call no-ops/fails safe — the pipeline
 * never crashes the queue on a missing/invalid key. Honours 429 with a bounded
 * backoff so a rate-limited sync retries rather than dropping work.
 */
class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private HttpFactory $http) {}

    public function isConfigured(): bool
    {
        return filled($this->key());
    }

    public function validateKey(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            // GET /v1/models is the cheapest authenticated call — a 2xx proves
            // the key is accepted without spending an embedding request.
            return $this->client()->get($this->url('/models'))->successful();
        } catch (Throwable $e) {
            Log::warning('OpenAI key validation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        if (! $this->isConfigured()) {
            // Defensive: callers gate on isConfigured(), but never explode here.
            throw new EmbeddingProviderUnavailable('OpenAI embeddings requested with no API key configured.');
        }

        $response = $this->postWithBackoff('/embeddings', [
            'model' => $this->model(),
            'input' => array_values($texts),
            'dimensions' => (int) config('lodestar.embeddings.dimensions', 1536),
        ]);

        // OpenAI returns data sorted by `index`; sort defensively, then pull the
        // raw vectors back out in input order.
        $data = $response->json('data', []);
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            fn (array $row) => array_map('floatval', $row['embedding'] ?? []),
            $data
        );
    }

    public function model(): string
    {
        return (string) config('lodestar.embeddings.model', 'text-embedding-3-small');
    }

    /** POST with a bounded retry on 429 / transient 5xx (exponential-ish backoff). */
    private function postWithBackoff(string $path, array $payload): Response
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $response = $this->client()->post($this->url($path), $payload);

            if ($response->successful()) {
                return $response;
            }

            $retryable = $response->status() === 429 || $response->serverError();
            if (! $retryable || $attempt >= self::MAX_ATTEMPTS) {
                throw new EmbeddingProviderUnavailable(
                    "OpenAI embeddings request failed (HTTP {$response->status()}): ".$response->body()
                );
            }

            // Honour Retry-After when present, else a small exponential backoff.
            $wait = (int) ($response->header('Retry-After') ?: 2 ** $attempt);
            usleep(min($wait, 30) * 1_000_000);
        }
    }

    private function client()
    {
        return $this->http
            ->withToken($this->key())
            ->acceptJson()
            ->timeout(30);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').$path;
    }

    private function key(): ?string
    {
        $key = config('services.openai.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
