<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Playbook;
use App\Models\PlaybookVersion;
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
 * composed across scopes (system → team → project → personal). This page shows
 * the EFFECTIVE composed prompt per phase; the filterable authoring overview
 * (version history, diff) lands in P4.
 *
 * Change control is human-gated: anyone who can reach a scope may PROPOSE a
 * version; only an assigned approver may APPROVE (make it active). An AI (over
 * MCP) can only ever propose. The rule lives on {@see Playbook::submitVersion()}.
 */
class PlaybookController extends Controller
{
    /** The overview: the composed effective prompt per phase + a filterable list of every layer you can reach. */
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(Playbook::SCOPES)],
            'key' => ['nullable', 'string'],
            'team_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(PlaybookVersion::STATUSES)],
            'preview_project' => ['nullable', 'integer'],
        ]);

        // Preview composition as it would run on a chosen project (adds the
        // team + project layers), else the plain system + personal view.
        $previewProject = ($filters['preview_project'] ?? null)
            ? Project::accessibleBy($user)->with('team')->find($filters['preview_project'])
            : null;

        $slots = $this->accessibleSlots($user)
            ->with(['owner', 'activeVersion'])
            ->withCount(['versions as proposed_count' => fn ($q) => $q->where('status', PlaybookVersion::STATUS_PROPOSED)])
            ->when($filters['scope'] ?? null, fn ($q, $s) => $q->where('scope', $s))
            ->when($filters['key'] ?? null, fn ($q, $k) => $q->where('key', $k))
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('owner_type', Team::class)->where('owner_id', $id))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('owner_type', Project::class)->where('owner_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $st) => $q->whereHas('versions', fn ($v) => $v->where('status', $st)))
            ->orderBy('key')->orderBy('scope')
            ->get();

        // Composed for the previewed project (system → team → project → personal),
        // or just system + personal when no project is chosen.
        $composed = collect(Playbook::PHASES)->mapWithKeys(
            fn (string $phase) => [$phase => Playbook::compose($user, $previewProject, $phase)],
        );

        return view('settings.playbooks', [
            'phases' => Playbook::PHASES,
            'phaseLabels' => Playbook::PHASE_LABELS,
            'composed' => $composed,
            'previewProject' => $previewProject,
            'slots' => $slots,
            'filters' => $filters,
            'scopes' => Playbook::SCOPES,
            'statuses' => PlaybookVersion::STATUSES,
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
