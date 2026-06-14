<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;

/**
 * The four-step "get set up" checklist shown to a fresh user. Each step's
 * "done" flag is a cheap exists() query against the user's own data, so the
 * banner stays in step with reality and hides itself once all four are done.
 */
trait BuildsOnboarding
{
    /** @return array{complete: bool, doneCount: int, steps: array<int, array{label: string, done: bool, href: string, help: string}>} */
    protected function onboarding(User $user): array
    {
        $hasGithub = $user->githubConnections()->exists();
        $hasProject = $user->projects()->exists();
        $hasRepo = $user->projects()->whereHas('repositories')->exists();
        $hasToken = $user->tokens()->exists();

        // First project — used to deep-link step 3 straight to a board's repos.
        $firstProject = $hasProject ? $user->projects()->oldest('id')->first() : null;

        $steps = [
            [
                'label' => 'Connect a GitHub account',
                'done' => $hasGithub,
                'href' => route('github.index'),
                'help' => 'Paste a token so Lodestar can read your repositories.',
            ],
            [
                'label' => 'Create a project',
                'done' => $hasProject,
                'href' => route('projects.index'),
                'help' => 'A project groups the repos and tasks that share a goal.',
            ],
            [
                'label' => 'Link a repository to a project',
                'done' => $hasRepo,
                'href' => $firstProject
                    ? route('repositories.index', $firstProject)
                    : route('projects.index'),
                'help' => 'Point a project at the repo your agents will work in.',
            ],
            [
                'label' => 'Connect a coding agent',
                'done' => $hasToken,
                'href' => route('agent-tokens.index'),
                'help' => 'Mint a token so your agent can drive the board over MCP.',
            ],
        ];

        $doneCount = count(array_filter($steps, fn ($s) => $s['done']));

        return [
            'complete' => $doneCount === count($steps),
            'doneCount' => $doneCount,
            'steps' => $steps,
        ];
    }
}
