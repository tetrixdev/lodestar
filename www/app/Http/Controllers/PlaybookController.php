<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Playbook;
use App\Models\PlaybookVersion;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\LineDiff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The playbooks page + change control. Playbooks are layered: a phase prompt is
 * composed across scopes (system → team → project → personal). The overview is a
 * SINGLE context-scoped list: pick a context (just you, or a project) and see,
 * per phase, the layers that compose there plus a preview of the composed prompt.
 * A layer's own page ({@see show()}) carries version history and the two-version diff.
 *
 * Change control is human-gated: anyone who can reach a scope may PROPOSE a
 * version; only an assigned approver may APPROVE (make it active). An AI (over
 * MCP) can only ever propose. The rule lives on {@see Playbook::submitVersion()}.
 */
class PlaybookController extends Controller
{
    /**
     * The overview: ONE context-scoped list. You pick a context (just you, or a
     * project) and the page shows, per phase, exactly the layers that compose in
     * that context — each with a preview of its body and a preview of the whole
     * composed prompt — plus the named playbooks reachable there. No separate
     * "effective prompts" grid and "all layers" filter: the context IS the filter.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $data = $request->validate([
            'context' => ['nullable', 'integer'], // a project id, or absent = "just me"
        ]);

        // The context is a project the user can reach (adds its team + project
        // layers), or null = the plain system + personal view ("just me").
        $contextProject = ($data['context'] ?? null)
            ? Project::accessibleBy($user)->with('team')->find($data['context'])
            : null;

        // Every layer the user can reach (system + own personal + their teams' +
        // their projects'), keyed for quick lookup while building the per-phase view.
        $accessible = $this->accessibleSlots($user)
            ->with(['owner', 'activeVersion'])
            ->withCount(['versions as proposed_count' => fn ($q) => $q->where('status', PlaybookVersion::STATUS_PROPOSED)])
            ->get();

        $team = $contextProject?->team;
        $allowPersonal = $team === null ? true : (bool) $team->allow_personal_instructions;

        // Which (scope, owner) layers actually apply in this context, in compose order.
        $applies = function (Playbook $slot) use ($user, $team, $contextProject, $allowPersonal): bool {
            return match ($slot->scope) {
                Playbook::SCOPE_SYSTEM => true,
                Playbook::SCOPE_TEAM => $team !== null && (int) $slot->owner_id === $team->id,
                Playbook::SCOPE_PROJECT => $contextProject !== null && (int) $slot->owner_id === $contextProject->id,
                Playbook::SCOPE_PERSONAL => $allowPersonal && (int) $slot->owner_id === $user->id,
                default => false,
            };
        };
        $scopeOrder = array_flip(Playbook::SCOPES); // system → team → project → personal

        // Per phase: the composed prompt + the in-context layer rows (for the list).
        $phases = collect(Playbook::PHASES)->map(function (string $phase) use ($accessible, $applies, $scopeOrder, $user, $contextProject) {
            $layers = $accessible
                ->where('key', $phase)
                ->filter($applies)
                ->sortBy(fn (Playbook $s) => $scopeOrder[$s->scope])
                ->values();

            return [
                'key' => $phase,
                'label' => Playbook::PHASE_LABELS[$phase] ?? $phase,
                'layers' => $layers,
                'composed' => Playbook::compose($user, $contextProject, $phase),
            ];
        });

        // Named (non-phase) playbooks reachable in this context — most-specific scope per key.
        $namedRank = [Playbook::SCOPE_PROJECT => 0, Playbook::SCOPE_PERSONAL => 1, Playbook::SCOPE_TEAM => 2, Playbook::SCOPE_SYSTEM => 3];
        $named = $accessible
            ->reject(fn (Playbook $s) => Playbook::isPhase($s->key))
            ->filter($applies)
            ->groupBy('key')
            ->map(fn ($group) => $group->sortBy(fn (Playbook $s) => $namedRank[$s->scope])->first())
            ->sortKeys()
            ->values();

        return view('settings.playbooks', [
            'phases' => $phases,
            'phaseKeys' => Playbook::PHASES,
            'phaseLabels' => Playbook::PHASE_LABELS,
            'named' => $named,
            'contextProject' => $contextProject,
            'allowPersonal' => $allowPersonal,
            'teams' => $user->teams()->orderBy('name')->get(),
            'projects' => Project::accessibleBy($user)->orderBy('name')->get(),
        ]);
    }

    /** A single layer: its versions, the change-control actions, and a two-version diff. */
    public function show(Request $request, Playbook $playbook): View
    {
        $user = $request->user();
        abort_unless($playbook->isSystem() || $playbook->canBeAccessedBy($user), 403);

        $playbook->load(['owner', 'versions' => fn ($q) => $q->latest('version'), 'versions.author']);
        $versions = $playbook->versions;

        // Diff a→b: default to the active version vs the newest proposed (else the two newest).
        $active = $versions->firstWhere('status', PlaybookVersion::STATUS_ACTIVE);
        $proposed = $versions->firstWhere('status', PlaybookVersion::STATUS_PROPOSED);
        $a = $versions->firstWhere('id', (int) $request->query('a')) ?? $active ?? $versions->get(1);
        $b = $versions->firstWhere('id', (int) $request->query('b')) ?? $proposed ?? $versions->first();

        $diff = ($a && $b && $a->isNot($b)) ? LineDiff::between($a->body, $b->body) : null;

        return view('settings.playbook-show', [
            'playbook' => $playbook,
            'versions' => $versions,
            'canApprove' => $playbook->canBeApprovedBy($user),
            'canPropose' => $playbook->canBeAccessedBy($user),
            'diffA' => $a,
            'diffB' => $b,
            'diff' => $diff,
        ]);
    }

    /** Every playbook slot the user can reach: system + own personal + their teams' + their projects'. */
    private function accessibleSlots(User $user)
    {
        $projectIds = Project::accessibleBy($user)->pluck('id');

        return Playbook::query()->where(function ($q) use ($user, $projectIds) {
            $q->where('scope', Playbook::SCOPE_SYSTEM)
                ->orWhere(fn ($q) => $q->where('scope', Playbook::SCOPE_PERSONAL)
                    ->where('owner_type', User::class)->where('owner_id', $user->id))
                ->orWhere(fn ($q) => $q->where('scope', Playbook::SCOPE_TEAM)
                    ->where('owner_type', Team::class)->whereIn('owner_id', $user->teamIds()))
                ->orWhere(fn ($q) => $q->where('scope', Playbook::SCOPE_PROJECT)
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
            'scope' => ['required', Rule::in([Playbook::SCOPE_PERSONAL, Playbook::SCOPE_TEAM, Playbook::SCOPE_PROJECT])],
            'key' => ['required', 'string', 'max:255', $this->notReservedKey()],
            'title' => ['required', 'string', 'max:255'],
            'summary' => $this->summaryRule((string) $request->input('key')),
            'body' => ['required', 'string'],
            'mode' => ['nullable', Rule::in(Playbook::MODES)],
            'note' => ['nullable', 'string'],
            'team_id' => ['required_if:scope,'.Playbook::SCOPE_TEAM, 'integer'],
            'project_id' => ['required_if:scope,'.Playbook::SCOPE_PROJECT, 'integer'],
        ]);

        $owner = $this->resolveOwner($request, $data);

        $slot = Playbook::ensureSlot($data['scope'], $owner, $data['key'], $data['title']);
        abort_unless($slot->canBeAccessedBy($user), 403);

        $version = $slot->submitVersion(
            $data['title'], $data['summary'] ?? null, $data['body'], $user,
            byAi: false, note: $data['note'] ?? null, mode: $data['mode'] ?? Playbook::MODE_APPEND,
        );

        return back()->with('status', $version->isActive() ? 'playbook-published' : 'playbook-proposed');
    }

    /** Make a proposed version live (archiving the prior active). Approver only. */
    public function approve(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $slot = $version->playbook;
        abort_unless($slot->canBeApprovedBy($request->user()), 403);
        abort_unless($version->isProposed(), 422);

        $slot->activate($version);

        return back()->with('status', 'playbook-approved');
    }

    /**
     * Approve a proposal *with edits*: publish the amended body as a new active
     * version (authored by the approver), and archive the original proposal with
     * a note recording it was amended into the new one. Approver only.
     */
    public function approveWithEdits(Request $request, PlaybookVersion $version): RedirectResponse
    {
        $slot = $version->playbook;
        abort_unless($slot->canBeApprovedBy($request->user()), 403);
        abort_unless($version->isProposed(), 422);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => $this->summaryRule($slot->key),
            'mode' => ['nullable', Rule::in(Playbook::MODES)],
            'body' => ['required', 'string'],
        ]);

        $new = $slot->publish($data['title'], $data['summary'] ?? null, $data['body'], $request->user(), mode: $data['mode'] ?? Playbook::MODE_APPEND);
        $version->update([
            'status' => PlaybookVersion::STATUS_ARCHIVED,
            'note' => 'Amended into v'.$new->version.' by '.$request->user()->name
                .($version->note ? ' — '.$version->note : ''),
        ]);

        return back()->with('status', 'playbook-approved');
    }

    /** Reject a proposed version. Approver only. */
    public function reject(Request $request, PlaybookVersion $version): RedirectResponse
    {
        abort_unless($version->playbook->canBeApprovedBy($request->user()), 403);
        abort_unless($version->isProposed(), 422);

        $version->update(['status' => PlaybookVersion::STATUS_REJECTED]);

        return back()->with('status', 'playbook-rejected');
    }

    /**
     * Summary is REQUIRED for named (on-demand) playbooks — that's the line the
     * `main` catalog shows so an agent knows when to pull it — and optional for
     * phase keys (which compose automatically and aren't catalogued).
     */
    private function summaryRule(string $key): array
    {
        return Playbook::isPhase($key)
            ? ['nullable', 'string', 'max:255']
            : ['required', 'string', 'max:255'];
    }

    /** A validation rule rejecting Lodestar-reserved key prefixes. */
    private function notReservedKey(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && Playbook::isReservedKey($value)) {
                $fail('Keys starting with "'.Playbook::RESERVED_KEY_PREFIX.'" are reserved for Lodestar.');
            }
        };
    }

    /** Resolve and access-check the scope owner for a proposal. */
    private function resolveOwner(Request $request, array $data): ?Model
    {
        $user = $request->user();

        return match ($data['scope']) {
            Playbook::SCOPE_PERSONAL => $user,
            Playbook::SCOPE_TEAM => tap(
                Team::findOrFail($data['team_id']),
                fn (Team $team) => abort_unless($user->isInTeam($team->id), 403),
            ),
            Playbook::SCOPE_PROJECT => Project::accessibleBy($user)->findOrFail($data['project_id']),
            default => abort(403),
        };
    }
}
