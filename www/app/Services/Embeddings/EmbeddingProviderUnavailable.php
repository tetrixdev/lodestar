<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use RuntimeException;

/**
 * Thrown when the embedding provider can't fulfil a request (no key, or the API
 * failed after backoff). The jobs catch it and fail safe — the queue is never
 * crashed by a missing/invalid key or a flaky OpenAI.
 */
class EmbeddingProviderUnavailable extends RuntimeException {}
