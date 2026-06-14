<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GitHubAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manage a user's linked GitHub accounts. Each connection is one token, verified
 * against GitHub on save (we store the resolved login so it's recognisable). A
 * user may keep several — work and personal — and link repos through whichever.
 */
class GithubConnectionController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.github', [
            'connections' => $request->user()->githubConnections()->latest()->get(),
        ]);
    }

    public function store(Request $request, GitHubAccount $github): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
        ]);

        $login = $github->login($data['token']);
        if (! $login) {
            return back()->withErrors(['token' => 'GitHub rejected that token — check it has repo scope and is valid.']);
        }

        $request->user()->githubConnections()->create([
            'label' => $data['label'],
            'token' => $data['token'],
            'github_login' => $login,
        ]);

        return redirect()->route('github.index')->with('status', "Connected GitHub as {$login}.");
    }

    public function destroy(Request $request, int $connection): RedirectResponse
    {
        $request->user()->githubConnections()->whereKey($connection)->delete();

        return redirect()->route('github.index')->with('status', 'Connection removed.');
    }
}
