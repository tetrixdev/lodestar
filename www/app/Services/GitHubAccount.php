<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Account/repo verification against the GitHub API for a given token — used when
 * a user connects an account or links a repository, so Lodestar confirms the
 * token works and can actually see the repo before storing it.
 */
class GitHubAccount
{
    private function client(string $token)
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    /** The login of the account a token belongs to, or null if the token is invalid. */
    public function login(string $token): ?string
    {
        $response = $this->client($token)->get('/user');

        return $response->successful() ? $response->json('login') : null;
    }

    /**
     * Confirm a token can see a repo and return its default branch, or null if
     * the repo is missing / not visible to this token.
     */
    public function defaultBranch(string $token, string $fullName): ?string
    {
        $response = $this->client($token)->get("/repos/{$fullName}");

        return $response->successful() ? $response->json('default_branch') : null;
    }
}
