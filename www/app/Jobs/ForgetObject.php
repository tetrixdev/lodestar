<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Embeddings\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drop one object's stored vector after it was deleted. Runs on the dedicated
 * `embeddings` queue and fails safe — a delete error is logged, never thrown,
 * so cleanup never crashes the queue. Idempotent (deleting a missing row is a
 * no-op), so a retry is harmless.
 */
class ForgetObject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param  class-string  $type */
    public function __construct(public string $type, public int|string $id)
    {
        $this->onQueue(config('lodestar.embeddings.queue', 'embeddings'));
    }

    public function handle(EmbeddingService $embeddings): void
    {
        try {
            $embeddings->forget($this->type, $this->id);
        } catch (Throwable $e) {
            Log::warning('ForgetObject failed', [
                'type' => $this->type,
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
