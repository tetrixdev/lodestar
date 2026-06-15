<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillVersion;
use App\Models\Team;
use App\Models\User;
use App\Support\LineDiff;
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
    /** The overview: the composed effective prompt per phase + a filterable list of every layer you can reach. */
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(Skill::SCOPES)],
            'key' => ['nullable', 'string'],
            'team_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(SkillVersion::STATUSES)],
        ]);

        $slots = $this->accessibleSlots($user)
            ->with(['owner', 'activeVersion'])
            ->withCount(['versions as proposed_count' => fn ($q) => $q->where('status', SkillVersion::STATUS_PROPOSED)])
            ->when($filters['scope'] ?? null, fn ($q, $s) => $q->where('scope', $s))
            ->when($filters['key'] ?? null, fn ($q, $k) => $q->where('key', $k))
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('owner_type', Team::class)->where('owner_id', $id))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('owner_type', Project::class)->where('owner_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $st) => $q->whereHas('versions', fn ($v) => $v->where('status', $st)))
            ->orderBy('key')->orderBy('scope')
            ->get();

        // Composed without a project: the system base + this user's personal layer.
        $composed = collect(Skill::PHASES)->mapWithKeys(
            fn (string $phase) => [$phase => Skill::compose($user, null, $phase)],
        );

        return view('settings.skills', [
            'phases' => Skill::PHASES,
            'phaseLabels' => Skill::PHASE_LABELS,
            'composed' => $composed,
            'slots' => $slots,
            'filters' => $filters,
            'scopes' => Skill::SCOPES,
            'statuses' => SkillVersion::STATUSES,
            'teams' => $user->teams()->orderBy('name')->get(),
            'projects' => Project::accessibleBy($user)->orderBy('name')->get(),
        ]);
    }

    /** A single layer: its versions, the change-control actions, and a two-version diff. */
    public function show(Request $request, Skill $skill): View
    {
        $user = $request->user();
        abort_unless($skill->isSystem() || $skill->canBeAccessedBy($user), 403);

        $skill->load(['owner', 'versions' => fn ($q) => $q->latest('version'), 'versions.author']);
        $versions = $skill->versions;

        // Diff a→b: default to the active version vs the newest proposed (else the two newest).
        $active = $versions->firstWhere('status', SkillVersion::STATUS_ACTIVE);
        $proposed = $versions->firstWhere('status', SkillVersion::STATUS_PROPOSED);
        $a = $versions->firstWhere('id', (int) $request->query('a')) ?? $active ?? $versions->get(1);
        $b = $versions->firstWhere('id', (int) $request->query('b')) ?? $proposed ?? $versions->first();

        $diff = ($a && $b && $a->isNot($b)) ? LineDiff::between($a->body, $b->body) : null;

        return view('settings.skill-show', [
            'skill' => $skill,
            'versions' => $versions,
            'canApprove' => $skill->canBeApprovedBy($user),
            'canPropose' => $skill->canBeAccessedBy($user),
            'diffA' => $a,
            'diffB' => $b,
            'diff' => $diff,
        ]);
    }

    /** Every skill slot the user can reach: system + own personal + their teams' + their projects'. */
    private function accessibleSlots(User $user)
    {
        $projectIds = Project::accessibleBy($user)->pluck('id');

        return Skill::query()->where(function ($q) use ($user, $projectIds) {
            $q->where('scope', Skill::SCOPE_SYSTEM)
                ->orWhere(fn ($q) => $q->where('scope', Skill::SCOPE_PERSONAL)
                    ->where('owner_type', User::class)->where('owner_id', $user->id))
                ->orWhere(fn ($q) => $q->where('scope', Skill::SCOPE_TEAM)
                    ->where('owner_type', Team::class)->whereIn('owner_id', $user->teamIds()))
                ->orWhere(fn ($q) => $q->where('scope', Skill::SCOPE_PROJECT)
                    ->where('owner_type', Project::class)->whereIn('owner_id', $projectIds));
        });
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
