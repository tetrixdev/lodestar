<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Models\Embedding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The embedding pipeline's write side: embed an object (hash-gated so unchanged
 * text is skipped), store its vector, and forget it. Owns all the
 * pgvector-specific SQL (the `vector` column is written/read with raw
 * statements because Eloquent has no vector type). Provider-agnostic — it's
 * handed an {@see EmbeddingProvider} so tests can mock the API.
 */
class EmbeddingService
{
    public function __construct(private EmbeddingProvider $provider) {}

    /** Is the pipeline switched on (a provider key is configured)? */
    public function enabled(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * Embed (or re-embed) one object. No-ops when the provider is unconfigured
     * (fail-safe) or when the text hash matches the stored row (skip unchanged).
     * Returns true if a vector was (re)written, false if skipped.
     *
     * @param  Model&\App\Models\Concerns\Embeddable  $object
     */
    public function embed(Model $object): bool
    {
        if (! $this->provider->isConfigured()) {
            return false;
        }

        $text = trim($object->embeddingText());
        if ($text === '') {
            // Nothing to embed — make sure any stale vector is gone.
            $this->forget($object::class, $object->getKey());

            return false;
        }

        $hash = $this->hash($text);

        $existing = $this->row($object::class, $object->getKey());
        if ($existing !== null && $existing->content_hash === $hash && $existing->model === $this->provider->model()) {
            return false; // unchanged — skip the API call
        }

        $vector = $this->provider->embed([$text])[0] ?? null;
        if ($vector === null) {
            return false;
        }

        $this->store($object, $hash, $vector);

        return true;
    }

    /**
     * Batch-embed many objects in one provider round-trip, hash-gating each.
     * Returns the count actually (re)written.
     *
     * @param  iterable<Model&\App\Models\Concerns\Embeddable>  $objects
     */
    public function embedBatch(iterable $objects): int
    {
        if (! $this->provider->isConfigured()) {
            return 0;
        }

        // Collect the ones whose text actually changed (skip unchanged) so the
        // batch only spends API budget on real work.
        $pending = [];
        $texts = [];
        foreach ($objects as $object) {
            $text = trim($object->embeddingText());
            if ($text === '') {
                $this->forget($object::class, $object->getKey());

                continue;
            }
            $hash = $this->hash($text);
            $existing = $this->row($object::class, $object->getKey());
            if ($existing !== null && $existing->content_hash === $hash && $existing->model === $this->provider->model()) {
                continue;
            }
            $pending[] = ['object' => $object, 'hash' => $hash];
            $texts[] = $text;
        }

        if ($pending === []) {
            return 0;
        }

        $vectors = $this->provider->embed($texts);

        $written = 0;
        foreach ($pending as $i => $entry) {
            $vector = $vectors[$i] ?? null;
            if ($vector === null) {
                continue;
            }
            $this->store($entry['object'], $entry['hash'], $vector);
            $written++;
        }

        return $written;
    }

    /** Delete the stored vector for an object (idempotent). */
    public function forget(string $type, int|string $id): void
    {
        $this->query()
            ->where('embeddable_type', (new $type)->getMorphClass())
            ->where('embeddable_id', $id)
            ->delete();
    }

    /** The content hash a text would store under (exposed for the reconciler). */
    public function hash(string $text): string
    {
        return hash('sha256', $this->provider->model().':'.$text);
    }

    /** The single embedding row for an object, or null. */
    public function row(string $type, int|string $id): ?Embedding
    {
        return $this->query()
            ->where('embeddable_type', (new $type)->getMorphClass())
            ->where('embeddable_id', $id)
            ->first();
    }

    /**
     * Upsert the vector + denormalised tenant filter for an object. The scalar
     * columns go through Eloquent (so casts/timestamps apply); the `vector`
     * column is set with a raw UPDATE because Eloquent can't bind it.
     *
     * @param  Model&\App\Models\Concerns\Embeddable  $object
     * @param  list<float>  $vector
     */
    private function store(Model $object, string $hash, array $vector): void
    {
        $tenant = $object->embeddingTenant();

        $row = $this->query()->updateOrCreate(
            [
                'embeddable_type' => $object->getMorphClass(),
                'embeddable_id' => $object->getKey(),
            ],
            [
                'project_id' => $tenant['project_id'] ?? null,
                'team_id' => $tenant['team_id'] ?? null,
                'owner_user_id' => $tenant['owner_user_id'] ?? null,
                'is_system' => (bool) ($tenant['is_system'] ?? false),
                'scope' => $tenant['scope'] ?? null,
                'model' => $this->provider->model(),
                'content_hash' => $hash,
            ],
        );

        // Write the actual vector with a raw, cast UPDATE (pgvector). On sqlite
        // (non-vector tests) the column is text; store the literal so the row is
        // still self-consistent.
        $literal = '['.implode(',', $vector).']';
        if (DB::connection($this->connectionName())->getDriverName() === 'pgsql') {
            $this->connection()->update(
                'UPDATE embeddings SET embedding = ?::vector WHERE id = ?',
                [$literal, $row->id]
            );
        } else {
            $this->connection()->update(
                'UPDATE embeddings SET embedding = ? WHERE id = ?',
                [$literal, $row->id]
            );
        }
    }

    private function query()
    {
        return Embedding::query();
    }

    private function connection()
    {
        return DB::connection($this->connectionName());
    }

    private function connectionName(): ?string
    {
        return (new Embedding)->getConnectionName();
    }
}
