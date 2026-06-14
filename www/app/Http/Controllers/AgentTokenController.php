<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Connect a coding agent" — the per-machine Sanctum token manager. A user mints
 * one token per machine/agent (each independently revocable), pastes it into the
 * thin client, and the client sends it as `Authorization: Bearer <token>` to the
 * MCP server. The plaintext token is shown exactly once, right after creation.
 */
class AgentTokenController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.agent-tokens', [
            'tokens' => $request->user()->tokens()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $token = $request->user()->createToken($validated['name'], ['agent']);

        // The plaintext is only ever available here — flash it once for copying.
        return redirect()
            ->route('agent-tokens.index')
            ->with('plain_token', $token->plainTextToken)
            ->with('plain_token_name', $validated['name']);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return redirect()->route('agent-tokens.index')->with('status', 'token-revoked');
    }
}
