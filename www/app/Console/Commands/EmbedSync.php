<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Embedding;
use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * The embedding reconciler: the backstop that keeps the index honest when a
 * live model event was missed (a crash, a bulk import, a key that arrived
 * late). Mirrors the task reaper — scheduled every five minutes,
 * withoutOverlapping.
 *
 * It validates the provider key first (no key → it's a no-op, fail-safe), then
 * walks every embeddable type in batches: embeds objects that are new / changed
 * / missing a vector (hash-gated, so unchanged text costs no API call), and
 * deletes embeddings whose object has vanished. The live EmbedObject/ForgetObject
 * jobs do the common case; this catches the drift.
 */
class EmbedSync extends Command
{
    protected $signature = 'lodestar:embed-sync {--type= : Only reconcile this config type key (e.g. tasks)}';

    protected $description = 'Reconcile the embeddings index: embed new/changed/missing objects, drop orphaned vectors.';

    public function handle(EmbeddingService $service, EmbeddingProvider $provider): int
    {
        if (! $provider->isConfigured()) {
            $this->warn('No embedding provider key configured — skipping (operator opt-in).');

            return self::SUCCESS;
        }

        if (! $provider->validateKey()) {
            // Fail-safe: a bad key never crashes the schedule; it's logged + skipped.
            $this->error('Embedding provider key is set but did not validate — skipping this run.');

            return self::SUCCESS;
        }

        $types = config('lodestar.embeddings.types', []);
        if ($only = $this->option('type')) {
            $types = array_intersect_key($types, [$only => true]);
        }

        $batchSize = (int) config('lodestar.embeddings.batch_size', 100);
        $embedded = 0;
        $deleted = 0;

        foreach ($types as $modelClass) {
            $embedded += $this->reconcileType($service, $modelClass, $batchSize);
            $deleted += $this->dropOrphans($modelClass);
        }

        $this->info("Embed-sync done: {$embedded} (re)embedded, {$deleted} orphan".($deleted === 1 ? '' : 's').' dropped.');

        return self::SUCCESS;
    }

    /** Walk one model's rows in chunks, batch-embedding the new/changed ones. */
    private function reconcileType(EmbeddingService $service, string $modelClass, int $batchSize): int
    {
        $written = 0;

        $modelClass::query()->chunkById($batchSize, function ($objects) use ($service, &$written) {
            $written += $service->embedBatch($objects);
        });

        return $written;
    }

    /** Delete embeddings whose owning object no longer exists. */
    private function dropOrphans(string $modelClass): int
    {
        /** @var Model $probe */
        $probe = new $modelClass;
        $morph = $probe->getMorphClass();
        $table = $probe->getTable();
        $key = $probe->getKeyName();

        // Ids that still exist for this type (respects soft-deletes via the
        // model's default query scope).
        $liveIds = $modelClass::query()->pluck($key)->all();

        $query = Embedding::query()->where('embeddable_type', $morph);
        if ($liveIds !== []) {
            $query->whereNotIn('embeddable_id', $liveIds);
        }

        return $query->delete();
    }
}
