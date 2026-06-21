<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Embeddings\EmbeddingSearch;
use App\Services\Embeddings\SearchResultResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The in-app semantic search page (the human mirror of the `search` MCP tool).
 * Thin: it validates the query, delegates the embed+KNN+access-filter to
 * {@see EmbeddingSearch} and the hydration to {@see SearchResultResolver}, and
 * renders the rows. Access-filtered to the signed-in user, identically to the
 * MCP tool.
 */
class SearchController extends Controller
{
    public function index(Request $request, EmbeddingSearch $search, SearchResultResolver $resolver): View
    {
        $query = trim((string) $request->query('q', ''));
        $results = [];
        $ran = false;

        if ($query !== '' && $search->enabled()) {
            $ran = true;
            $hits = $search->search(user: $request->user(), query: $query, limit: 25);
            $results = $resolver->resolve($hits);
        }

        return view('search', [
            'query' => $query,
            'results' => $results,
            'ran' => $ran,
            'enabled' => $search->enabled(),
        ]);
    }
}
