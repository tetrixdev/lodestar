<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the `vector` extension that the embeddings / semantic-search pipeline
 * relies on (the `embeddings.embedding vector(1536)` column + the hnsw index).
 * Postgres-only: the production image is pgvector/pgvector:pg17 (a superset of
 * postgres:17). On sqlite (the default test connection) there is no extension
 * system and no `vector` type, so we no-op — the pgvector-backed tests run
 * against an isolated pgvector connection instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS vector');
        }
    }
};
