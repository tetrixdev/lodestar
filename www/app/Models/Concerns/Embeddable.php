<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Jobs\EmbedObject;
use App\Jobs\ForgetObject;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as part of the semantic-search index. A using model supplies:
 *  - {@see embeddingText()}    — the natural-language text to embed; and
 *  - {@see embeddingTenant()}  — the denormalised access-filter columns
 *                                (project_id / team_id / owner_user_id /
 *                                is_system / scope) copied onto the embedding
 *                                row so KNN search can filter in SQL.
 *
 * The trait wires the model events: a saved object is (re-)embedded
 * ({@see EmbedObject}, hash-gated so unchanged text is skipped), a deleted one
 * is forgotten ({@see ForgetObject}). Both jobs run on the dedicated
 * `embeddings` queue and fail safe — embedding never blocks a save.
 */
trait Embeddable
{
    /** The natural-language text representing this object for search. */
    abstract public function embeddingText(): string;

    /**
     * The denormalised tenant filter for this object's embedding row. Keys:
     * project_id, team_id, owner_user_id, is_system, scope (any may be null).
     *
     * @return array{project_id?: int|null, team_id?: int|null, owner_user_id?: int|null, is_system?: bool, scope?: string|null}
     */
    abstract public function embeddingTenant(): array;

    public static function bootEmbeddable(): void
    {
        static::saved(function (Model $model): void {
            EmbedObject::dispatch($model::class, $model->getKey());
        });

        static::deleted(function (Model $model): void {
            ForgetObject::dispatch($model::class, $model->getKey());
        });
    }
}
