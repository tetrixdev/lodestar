<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Embeddings\EmbeddingProvider;

/**
 * A deterministic in-memory embedding provider for tests — NO real OpenAI call
 * (and no real key). Produces a stable 1536-d vector per text, records every
 * embedded text, and lets a test simulate a missing/invalid key
 * (`$configured` / `$valid`) so the fail-safe paths can be exercised.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<string> every text passed to embed(), in order */
    public array $embedded = [];

    public int $calls = 0;

    public function __construct(
        public bool $configured = true,
        public bool $valid = true,
        public string $model = 'fake-embedding-model',
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function validateKey(): bool
    {
        return $this->configured && $this->valid;
    }

    public function embed(array $texts): array
    {
        $this->calls++;
        $out = [];
        foreach ($texts as $text) {
            $this->embedded[] = $text;
            $out[] = $this->vectorFor($text);
        }

        return $out;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** A stable, normalised pseudo-vector derived from the text. */
    private function vectorFor(string $text): array
    {
        $seed = crc32($text);
        $vec = [];
        for ($i = 0; $i < 1536; $i++) {
            $vec[] = ((($seed + $i * 2654435761) % 1000) / 1000) - 0.5;
        }

        return $vec;
    }
}
