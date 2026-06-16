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
     * @return list<array{path:string,status:string,old_path:?string,position:int,patch:?string,additions:int,deletions:int}>
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
                // GitHub omits `patch` for binary / oversized files — keep it null.
                'patch' => $f['patch'] ?? null,
                'additions' => $f['additions'] ?? 0,
                'deletions' => $f['deletions'] ?? 0,
            ])
            ->all();
    }

    /**
     * Resolve a ref (branch / tag / sha) to its full commit SHA, so a moving ref
     * like "main" is pinned at review-creation time.
     */
    public function resolveSha(string $repo, string $ref, ?string $token = null): string
    {
        $token ??= config('services.github.token');

        $response = Http::baseUrl('https://api.github.com')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->when($token, fn ($http) => $http->withToken($token))
            ->get("/repos/{$repo}/commits/".rawurlencode($ref));

        if ($response->failed()) {
            throw new RuntimeException(
                "GitHub could not resolve {$repo}@{$ref}: ".$response->status()
                .' '.($response->json('message') ?? '')
            );
        }

        return (string) $response->json('sha');
    }

    /** A blob fetched from the contents API: decoded UTF-8 text, or null with a flag set. */
    public const MAX_BLOB_BYTES = 1_000_000; // ~1MB — the contents API's own ceiling.

    /**
     * Fetch a single file's contents at a given commit. Returns the decoded
     * UTF-8 string, or null content with `too_large` / `binary` set when the file
     * cannot be shown inline.
     *
     * @return array{content:?string,too_large:bool,binary:bool}
     */
    public function blob(string $repo, string $sha, string $path, ?string $token = null): array
    {
        $token ??= config('services.github.token');

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        $response = Http::baseUrl('https://api.github.com')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->when($token, fn ($http) => $http->withToken($token))
            ->get("/repos/{$repo}/contents/{$encodedPath}", ['ref' => $sha]);

        if ($response->failed()) {
            // The contents API refuses files over its size ceiling (and large dirs)
            // with a 403 "too large" — treat that as a too-large blob, not an error.
            $message = (string) ($response->json('message') ?? '');
            if (str_contains(strtolower($message), 'too large')) {
                return ['content' => null, 'too_large' => true, 'binary' => false];
            }

            throw new RuntimeException(
                "GitHub could not read {$repo}:{$path}@{$sha}: ".$response->status().' '.$message
            );
        }

        if (($response->json('size') ?? 0) > self::MAX_BLOB_BYTES) {
            return ['content' => null, 'too_large' => true, 'binary' => false];
        }

        $encoding = $response->json('encoding');
        $raw = (string) $response->json('content', '');
        $decoded = $encoding === 'base64' ? base64_decode(str_replace("\n", '', $raw), true) : $raw;

        if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
            return ['content' => null, 'too_large' => false, 'binary' => true];
        }

        return ['content' => $decoded, 'too_large' => false, 'binary' => false];
    }
}
