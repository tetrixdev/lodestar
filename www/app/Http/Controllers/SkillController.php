<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillVersion;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The skills page + change control. Skills are layered: a phase prompt is
 * composed across scopes (system → team → project → personal). This page shows
 * the EFFECTIVE composed prompt per phase; the filterable authoring overview
 * (version history, diff) lands in P4.
 *
 * Change control is human-gated: anyone who can reach a scope may PROPOSE a
 * version; only an assigned approver may APPROVE (make it active). An AI (over
 * MCP) can only ever propose. The rule lives on {@see Skill::submitVersion()}.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Composed without a project: the system base + this user's personal layer.
        $composed = collect(Skill::PHASES)->mapWithKeys(
            fn (string $phase) => [$phase => Skill::compose($user, null, $phase)],
        );

        return view('settings.skills', [
            'phases' => Skill::PHASES,
            'phaseLabels' => Skill::PHASE_LABELS,
            'composed' => $composed,
        ]);
    }

    /**
     * Propose a version on a slot (creating the slot if needed). A human who can
     * approve the scope self-approves it live; everyone else's lands proposed.
     */
    public function propose(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'scope' => ['required', Rule::in([Skill::SCOPE_PERSONAL, Skill::SCOPE_TEAM, Skill::SCOPE_PROJECT])],
            'key' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'mode' => ['nullable', Rule::in(Skill::MODES)],
            'note' => ['nullable', 'string'],
            'team_id' => ['required_if:scope,'.Skill::SCOPE_TEAM, 'integer'],
            'project_id' => ['required_if:scope,'.Skill::SCOPE_PROJECT, 'integer'],
        ]);

        $owner = $this->resolveOwner($request, $data);

        $slot = Skill::ensureSlot($data['scope'], $owner, $data['key'], $data['mode'] ?? Skill::MODE_APPEND, $data['title']);
        abort_unless($slot->canBeAccessedBy($user), 403);

        $version = $slot->submitVersion($data['title'], $data['body'], $user, byAi: false, note: $data['note'] ?? null);

        return back()->with('status', $version->isActive() ? 'skill-published' : 'skill-proposed');
    }

    /** Make a proposed version live (archiving the prior active). Approver only. */
    public function approve(Request $request, SkillVersion $version): RedirectResponse
    {
        $slot = $version->skill;
        abort_unless($slot->canBeApprovedBy($request->user()), 403);
        abort_unless($version->isProposed(), 422);

        $slot->activate($version);

        return back()->with('status', 'skill-approved');
    }

    /** Reject a proposed version. Approver only. */
    public function reject(Request $request, SkillVersion $version): RedirectResponse
    {
        abort_unless($version->skill->canBeApprovedBy($request->user()), 403);
        abort_unless($version->isProposed(), 422);

        $version->update(['status' => SkillVersion::STATUS_REJECTED]);

        return back()->with('status', 'skill-rejected');
    }

    /**
     * Flip a slot between append and overwrite. This changes what the whole layer
     * does (overwrite discards everything above it), so it is an approver-only
     * control and the UI warns before applying it.
     */
    public function toggleMode(Request $request, Skill $skill): RedirectResponse
    {
        abort_unless($skill->canBeApprovedBy($request->user()), 403);

        $skill->update([
            'mode' => $skill->mode === Skill::MODE_OVERWRITE ? Skill::MODE_APPEND : Skill::MODE_OVERWRITE,
        ]);

        return back()->with('status', 'skill-mode-changed');
    }

    /** Resolve and access-check the scope owner for a proposal. */
    private function resolveOwner(Request $request, array $data): ?Model
    {
        $user = $request->user();

        return match ($data['scope']) {
            Skill::SCOPE_PERSONAL => $user,
            Skill::SCOPE_TEAM => tap(
                Team::findOrFail($data['team_id']),
                fn (Team $team) => abort_unless($user->isInTeam($team->id), 403),
            ),
            Skill::SCOPE_PROJECT => Project::accessibleBy($user)->findOrFail($data['project_id']),
            default => abort(403),
        };
    }
}
