<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\PgvectorTestCase;

/**
 * INFRA guard: the production image is pgvector, the migration enables the
 * `vector` extension, and the embeddings table's `embedding` column is a real
 * vector(1536) that round-trips. If this reds, the pipeline has no place to
 * store vectors.
 */
class PgvectorInfraTest extends PgvectorTestCase
{
    public function test_vector_extension_is_installed(): void
    {
        $row = DB::connection('pgvector')->selectOne(
            "SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'"
        );

        $this->assertNotNull($row, 'The `vector` extension is not installed — the enable-pgvector migration did not run.');
    }

    public function test_embeddings_embedding_column_is_a_vector_that_round_trips(): void
    {
        $conn = DB::connection('pgvector');

        // The column type is vector (not the text placeholder used on sqlite).
        $type = $conn->selectOne(
            "SELECT udt_name FROM information_schema.columns
             WHERE table_name = 'embeddings' AND column_name = 'embedding'"
        );
        $this->assertSame('vector', $type->udt_name);

        // A vector literal round-trips and cosine distance works.
        $vec = '['.implode(',', array_fill(0, 1536, 0.1)).']';
        $conn->insert(
            'INSERT INTO embeddings (embeddable_type, embeddable_id, model, content_hash, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?::vector, now(), now())',
            ['Probe', 1, 'test-model', 'hash', $vec]
        );

        $back = $conn->selectOne(
            'SELECT (embedding <=> ?::vector) AS distance FROM embeddings WHERE embeddable_type = ?',
            [$vec, 'Probe']
        );

        $this->assertNotNull($back);
        $this->assertEqualsWithDelta(0.0, (float) $back->distance, 1e-6, 'A vector compared with itself should have ~0 cosine distance.');
    }
}
