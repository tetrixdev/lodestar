<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Embeddings\EmbeddingSearch;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored embedding for a polymorphic object (the {@see Concerns\Embeddable}
 * owner). The vector itself (`embedding`) is a pgvector column read/written via
 * raw SQL in {@see EmbeddingService} /
 * {@see EmbeddingSearch} — Eloquent only carries the
 * scalar columns (the tenant filter, the content hash, the model). One row per
 * object (unique `embeddable_type` + `embeddable_id`).
 */
class Embedding extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /** The embedded object (Project, Task, …). */
    public function embeddable(): MorphTo
    {
        return $this->morphTo();
    }
}
