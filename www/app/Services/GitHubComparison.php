<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches the authoritative changed-file list for a comparison from the GitHub
 * compare API. This is deliberately server-side and AI-independent: the agent
 * groups what GitHub says changed, it never gets to decide the file set itself.
 *
 * The compare endpoint pages its `files` (100 per page); we walk the pages and
 * accumulate, so large diffs are retrieved in full rather than truncated. A diff
 * beyond MAX_FILES throws (rather than silently under-covering) so the coverage
 * guard can never read "complete" while files are missing.
 */
class GitHubComparison
{
    /** Files per page on the compare endpoint. */
    public const PER_PAGE = 100;

    /** Hard ceiling so a runaway/enormous diff fails loudly instead of looping. */
    public const MAX_FILES = 3000;

    /**
     * @return list<array{path:string,status:string,old_path:?string,position:int}>
     */
    public function files(string $repo, string $base, string $head, ?string $token = null): array
    {
        // Prefer the repository's own connection token; fall back to the server
        // token (v1 single-tenant) when none is given.
        $token ??= config('services.github.token');

        $path = "/repos/{$repo}/compare/".rawurlencode($base).'...'.rawurlencode($head);
        $maxPages = (int) (self::MAX_FILES / self::PER_PAGE);

        $files = [];
        for ($page = 1; $page <= $maxPages + 1; $page++) {
            $response = Http::baseUrl('https://api.github.com')
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->when($token, fn ($http) => $http->withToken($token))
                ->get($path, ['per_page' => self::PER_PAGE, 'page' => $page]);

            if ($response->failed()) {
                throw new RuntimeException(
                    "GitHub compare failed for {$repo} {$base}...{$head}: ".$response->status()
                    .' '.($response->json('message') ?? '')
                );
            }

            $chunk = $response->json('files', []);
            $files = array_merge($files, $chunk);

            // A short (or empty) page is the last one.
            if (count($chunk) < self::PER_PAGE) {
                break;
            }

            // Still full at the ceiling → the diff is too big to review in one pass.
            if ($page > $maxPages) {
                throw new RuntimeException(
                    "Comparison {$repo} {$base}...{$head} exceeds ".self::MAX_FILES
                    .' files — too large to review exhaustively in one pass. Split it into smaller comparisons.'
                );
            }
        }

        return collect($files)
            ->values()
            ->map(fn (array $f, int $i): array => [
                'path' => $f['filename'],
                'status' => $f['status'] ?? 'modified',
                'old_path' => $f['previous_filename'] ?? null,
                'position' => $i,
            ])
            ->all();
    }
}
