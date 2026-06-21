<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Concerns\Embeddable;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * (Re-)embed one object after it was saved. Carries the morph type + id (not
 * the model) so a stale snapshot is never embedded — it re-fetches current
 * state. Hash-gated in the service (unchanged text is skipped). Runs on the
 * dedicated `embeddings` queue and FAILS SAFE: any provider error is logged
 * and swallowed so a missing/invalid key or a flaky OpenAI never crashes the
 * queue or blocks the originating save.
 */
class EmbedObject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param  class-string  $type */
    public function __construct(public string $type, public int|string $id)
    {
        $this->onQueue(config('lodestar.embeddings.queue', 'embeddings'));
    }

    public function handle(EmbeddingService $embeddings): void
    {
        if (! $embeddings->enabled()) {
            return; // operator opt-in: no key, no work
        }

        $object = $this->type::query()->find($this->id);
        if ($object === null || ! in_array(Embeddable::class, class_uses_recursive($object), true)) {
            return; // gone, or not embeddable any more
        }

        try {
            $embeddings->embed($object);
        } catch (Throwable $e) {
            // Fail-safe: never crash the queue on an embedding error.
            Log::warning('EmbedObject failed', [
                'type' => $this->type,
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
