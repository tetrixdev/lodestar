<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Teams — a shared home for projects and approval rights. Everything here is
 * gated to teams the user belongs to; membership management is owner-only.
 */
class TeamController extends Controller
{
    /** The teams this user belongs to (owner is also a member). */
    public function index(Request $request): View
    {
        $teams = $request->user()
            ->teams()
            ->withCount(['members', 'projects'])
            ->orderBy('name')
            ->get();

        return view('settings.teams.index', ['teams' => $teams]);
    }

    /** Create a team; the model adds the owner as a member on create. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $team = Team::create([
            'name' => $data['name'],
            'owner_user_id' => $request->user()->id,
        ]);

        return redirect()->route('teams.show', $team)->with('status', 'Team created.');
    }

    /** The team page — settings + members. Visible to any member. */
    public function show(Request $request, Team $team): View
    {
        abort_unless($team->members()->whereKey($request->user()->id)->exists(), 403);

        $team->load(['members' => fn ($q) => $q->orderByPivot('role')]);

        return view('settings.teams.show', [
            'team' => $team,
            'isOwner' => $team->owner_user_id === $request->user()->id,
        ]);
    }

    /** Edit name + the personal-instructions toggle (owner only). */
    public function update(Request $request, Team $team): RedirectResponse
    {
        abort_unless($team->owner_user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'allow_personal_instructions' => ['nullable', 'boolean'],
        ]);

        $team->update([
            'name' => $data['name'],
            'allow_personal_instructions' => $request->boolean('allow_personal_instructions'),
        ]);

        return redirect()->route('teams.show', $team)->with('status', 'Team updated.');
    }

    /** Delete a team (owner only). Its projects' team_id null-on-delete via FK. */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        abort_unless($team->owner_user_id === $request->user()->id, 403);

        $team->delete();

        return redirect()->route('teams.index')->with('status', 'Team deleted.');
    }

    // ── membership (owner only) ──────────────────────────────────────────────

    /** Add an EXISTING user by email; unknown email → a validation error. */
    public function addMember(Request $request, Team $team): RedirectResponse
    {
        abort_unless($team->owner_user_id === $request->user()->id, 403);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:member,admin'],
            'can_approve_prompts' => ['nullable', 'boolean'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return back()->withErrors(['email' => 'No Lodestar user with that email — they need an account first.']);
        }

        $team->members()->syncWithoutDetaching([
            $user->id => [
                'role' => $data['role'],
                'can_approve_prompts' => $request->boolean('can_approve_prompts'),
            ],
        ]);

        return redirect()->route('teams.show', $team)->with('status', "Added {$user->name}.");
    }

    /** Toggle a member's role / approval rights (owner only; never the owner). */
    public function updateMember(Request $request, Team $team, User $user): RedirectResponse
    {
        abort_unless($team->owner_user_id === $request->user()->id, 403);
        abort_if($user->id === $team->owner_user_id, 403, 'The owner cannot be changed.');

        $data = $request->validate([
            'role' => ['required', 'in:member,admin'],
            'can_approve_prompts' => ['nullable', 'boolean'],
        ]);

        abort_unless($team->members()->whereKey($user->id)->exists(), 404);

        $team->members()->updateExistingPivot($user->id, [
            'role' => $data['role'],
            'can_approve_prompts' => $request->boolean('can_approve_prompts'),
        ]);

        return redirect()->route('teams.show', $team)->with('status', 'Member updated.');
    }

    /** Remove a member (owner only; never the owner). */
    public function removeMember(Request $request, Team $team, User $user): RedirectResponse
    {
        abort_unless($team->owner_user_id === $request->user()->id, 403);
        abort_if($user->id === $team->owner_user_id, 403, 'The owner cannot be removed.');

        $team->members()->detach($user->id);

        return redirect()->route('teams.show', $team)->with('status', 'Member removed.');
    }
}
