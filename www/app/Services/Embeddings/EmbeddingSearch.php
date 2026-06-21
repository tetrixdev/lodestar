<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Models\Embedding;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The embedding pipeline's read side: turn a query into a vector, run an
 * approximate-NN search over the index, and ACCESS-FILTER the candidates to the
 * caller's reachable objects — all in SQL (no per-row authorization). The
 * filter mirrors the web/MCP tenancy: an object is visible if its embedding's
 * denormalised `project_id` is one the caller can reach, OR it's a system
 * object (e.g. a system playbook, readable by everyone), OR it's the caller's
 * own personal-scope object, OR it's in one of the caller's teams.
 *
 * Degrades safely: with no provider key (or on any embed failure) it returns an
 * empty result rather than throwing — search "works", just finds nothing.
 */
class EmbeddingSearch
{
    public function __construct(private EmbeddingProvider $provider) {}

    public function enabled(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * Search the index for $query as $user. Returns rows
     * {embeddable_type, embeddable_id, distance}, nearest first, already
     * access-filtered. Optionally restrict to morph types and/or one project.
     *
     * @param  list<string>  $types  morph class names to include (empty = all)
     * @return list<array{embeddable_type: string, embeddable_id: int, distance: float}>
     */
    public function search(User $user, string $query, array $types = [], ?int $projectId = null, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || ! $this->provider->isConfigured()) {
            return [];
        }

        try {
            $vector = $this->provider->embed([$query])[0] ?? null;
        } catch (Throwable) {
            return []; // OpenAI down → search degrades to empty, never errors
        }
        if ($vector === null) {
            return [];
        }

        $literal = '['.implode(',', $vector).']';
        $connection = DB::connection((new Embedding)->getConnectionName());

        $builder = $connection->table('embeddings');
        $this->applyAccessFilter($builder, $user);

        if ($types !== []) {
            $morphTypes = array_map(fn (string $t) => class_exists($t) ? (new $t)->getMorphClass() : $t, $types);
            $builder->whereIn('embeddable_type', $morphTypes);
        }
        if ($projectId !== null) {
            $builder->where('project_id', $projectId);
        }

        // Only rows that actually have a vector, ordered by cosine distance.
        $isPg = $connection->getDriverName() === 'pgsql';
        if ($isPg) {
            $builder->whereNotNull('embedding')
                ->selectRaw('embeddable_type, embeddable_id, (embedding <=> ?::vector) AS distance', [$literal])
                ->orderByRaw('embedding <=> ?::vector', [$literal]);
        } else {
            // sqlite has no vector ops — stable, deterministic fallback so the
            // non-pgvector tests can still exercise the access filter.
            $builder->whereNotNull('embedding')
                ->selectRaw('embeddable_type, embeddable_id, 0 AS distance')
                ->orderBy('embeddable_id');
        }

        return $builder->limit($limit)->get()
            ->map(fn ($r) => [
                'embeddable_type' => (string) $r->embeddable_type,
                'embeddable_id' => (int) $r->embeddable_id,
                'distance' => (float) $r->distance,
            ])
            ->all();
    }

    /**
     * The tenancy filter, built once and applied in SQL: project_id IN
     * accessible OR is_system OR (scope=personal AND owner=me) OR (scope=team
     * AND team IN my teams).
     */
    private function applyAccessFilter(Builder $builder, User $user): void
    {
        $projectIds = Project::accessibleBy($user)->pluck('id')->all();
        $teamIds = $user->teamIds();

        $builder->where(function (Builder $q) use ($user, $projectIds, $teamIds) {
            if ($projectIds !== []) {
                $q->whereIn('project_id', $projectIds);
            }
            $q->orWhere('is_system', true);
            $q->orWhere(function (Builder $q) use ($user) {
                $q->where('scope', 'personal')->where('owner_user_id', $user->id);
            });
            if ($teamIds !== []) {
                $q->orWhere(function (Builder $q) use ($teamIds) {
                    $q->where('scope', 'team')->whereIn('team_id', $teamIds);
                });
            }
        });
    }
}
