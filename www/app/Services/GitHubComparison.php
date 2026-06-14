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
 * The compare endpoint returns up to 300 files in one response; a diff that hits
 * that cap throws (rather than silently under-covering), so the coverage guard
 * can never read "complete" while files are missing.
 */
class GitHubComparison
{
    /** GitHub's per-response file cap on the compare endpoint. */
    public const FILE_CAP = 300;

    /**
     * @return list<array{path:string,status:string,old_path:?string,position:int}>
     */
    public function files(string $repo, string $base, string $head, ?string $token = null): array
    {
        // Prefer the repository's own connection token; fall back to the server
        // token (v1 single-tenant) when none is given.
        $token ??= config('services.github.token');

        $response = Http::baseUrl('https://api.github.com')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->when($token, fn ($http) => $http->withToken($token))
            ->get("/repos/{$repo}/compare/".rawurlencode($base).'...'.rawurlencode($head));

        if ($response->failed()) {
            throw new RuntimeException(
                "GitHub compare failed for {$repo} {$base}...{$head}: ".$response->status()
                .' '.($response->json('message') ?? '')
            );
        }

        $raw = $response->json('files', []);

        // The compare endpoint caps `files` at 300 with no file-level pagination.
        // Rather than silently under-cover a huge diff (which would let the
        // coverage guard read "complete" while missing files), fail loudly so the
        // review is split. Paging large diffs is a tracked follow-up.
        if (count($raw) >= self::FILE_CAP) {
            throw new RuntimeException(
                "Comparison {$repo} {$base}...{$head} has ".self::FILE_CAP.'+ files — too large to review exhaustively in one pass yet. Split it into smaller comparisons.'
            );
        }

        return collect($raw)
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
