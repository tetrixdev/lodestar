<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

/**
 * The embedding boundary: turn text into vectors, and check the credential is
 * usable. One implementation talks to OpenAI ({@see OpenAiEmbeddingProvider});
 * tests bind a fake so no real API call (and no real key) is needed.
 */
interface EmbeddingProvider
{
    /** Is a credential configured for this provider at all? */
    public function isConfigured(): bool;

    /**
     * Cheap sanity check that the configured key works (a real call the caller
     * can run before a sync). Returns true if usable; false (never throws) if
     * the key is missing/invalid/unreachable.
     */
    public function validateKey(): bool;

    /**
     * Embed a batch of texts, returning one vector (list<float>) per input in
     * order. Implementations batch + back off internally.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /** The model id whose vectors this provider produces (for provenance). */
    public function model(): string;
}
