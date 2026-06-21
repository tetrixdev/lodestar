<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Embeddings\EmbeddingSearch;
use App\Services\Embeddings\SearchResultResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Semantic search across the caller's reachable Lodestar objects (projects,
 * tasks, work-sessions, reviews, comments, findings + system playbooks). Embeds
 * the query, runs a KNN vector search, and access-filters the hits in SQL to
 * exactly what the token's user may see — the same tenancy as every other tool.
 *
 * Degrades safely: with no embedding key configured (operator opt-in) it
 * returns an empty result + a note, never an error.
 */
#[Description('Semantic (meaning-based) search across your projects, tasks, work-sessions, reviews, comments, findings and the system playbooks. Returns the nearest matches you can access as {type,id,title,snippet,url}. Optional filters: types[] (e.g. ["task","project"]), project (id/slug) and limit. Returns empty (with a note) when semantic search is not configured on this deployment.')]
#[Name('search')]
class Search extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:1'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string'],
            'project' => ['sometimes'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $this->currentUser($request);
        $search = app(EmbeddingSearch::class);

        if (! $search->enabled()) {
            return Response::json([
                'results' => [],
                'note' => 'Semantic search is not configured on this deployment (no embedding key).',
            ]);
        }

        // Resolve an optional project filter through the same access scope as
        // everything else; an unknown/forbidden ref yields no project rows.
        $projectId = null;
        if (! empty($data['project'])) {
            $project = $this->ownedProject($request, $data['project']);
            $projectId = $project?->id ?? -1; // -1 => matches nothing (forbidden)
        }

        $resolver = app(SearchResultResolver::class);
        $types = $resolver->morphTypesFor($data['types'] ?? []);

        $hits = $search->search(
            user: $user,
            query: $data['query'],
            types: $types,
            projectId: $projectId,
            limit: (int) ($data['limit'] ?? 20),
        );

        return Response::json([
            'results' => $resolver->resolve($hits),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The natural-language search query.'),
            'types' => $schema->array()->items($schema->string())
                ->description('Optional: restrict to these object types (project, task, work_session, review, review_section, review_finding, task_comment, playbook).'),
            'project' => $schema->string()->description('Optional: restrict to one project (id or slug you can access).'),
            'limit' => $schema->integer()->description('Optional: max results (1–50, default 20).'),
        ];
    }
}
