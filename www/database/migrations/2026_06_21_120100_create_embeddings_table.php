<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The semantic-search index: one row per embedded object (polymorphic
 * `embeddable_type` + `embeddable_id`, unique together — one vector per object).
 *
 * Denormalised tenant-filter columns are copied off the owning object at embed
 * time so KNN search can filter to the caller's accessible set in SQL without a
 * join per candidate: `project_id` (the owning project, when any), `team_id`,
 * `owner_user_id`, `is_system` (system playbooks are readable by everyone) and
 * `scope` (personal/team/project/system). `content_hash` gates re-embedding:
 * unchanged text is skipped. `model` records which embedding model produced the
 * vector. `embedding` is the 1536-d vector itself.
 *
 * On Postgres (production = pgvector/pgvector:pg17) the embedding column is a
 * real `vector(1536)` with an hnsw cosine index, added via raw SQL because the
 * Blueprint has no vector type. On sqlite (the default test connection) there
 * is no vector type, so the column is a text placeholder — the vector-backed
 * tests run against an isolated pgvector connection instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('embeddings', function (Blueprint $table) use ($isPg) {
            $table->id();

            // The polymorphic owner — one embedding per object.
            $table->string('embeddable_type');
            $table->unsignedBigInteger('embeddable_id');

            // Denormalised tenant filter (copied off the owner at embed time).
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->boolean('is_system')->default(false)->index();
            $table->string('scope')->nullable();

            // Provenance + change gate.
            $table->string('model');
            $table->string('content_hash');

            // The vector. On pgsql this text column is immediately replaced by a
            // real vector(1536) below; on sqlite it stays a harmless placeholder.
            $table->text('embedding')->nullable();

            $table->timestamps();

            $table->unique(['embeddable_type', 'embeddable_id']);
        });

        if ($isPg) {
            // Swap the placeholder text column for a real pgvector column, then
            // add the approximate-NN index (hnsw, cosine distance operator).
            DB::statement('ALTER TABLE embeddings DROP COLUMN embedding');
            DB::statement('ALTER TABLE embeddings ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX embeddings_embedding_hnsw_idx ON embeddings USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
