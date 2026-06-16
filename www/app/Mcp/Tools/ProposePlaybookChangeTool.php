<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\StalePlaybookHashException;
use App\Models\Playbook;
use App\Models\PlaybookVersion;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Propose a change to a playbook — a new version of a (scope, key) slot, creating the slot if needed. Scope is "personal" (your own layer), "team" (needs team_id) or "project" (needs project). Your proposal is ALWAYS recorded as PROPOSED and awaits a human approver; an AI can never make a playbook live, not even on your own personal layer. System playbooks are seeded, not proposable here.')]
#[Name('propose_playbook_change')]
class ProposePlaybookChangeTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'scope' => ['required', 'in:'.Playbook::SCOPE_PERSONAL.','.Playbook::SCOPE_TEAM.','.Playbook::SCOPE_PROJECT],
            'key' => ['required', 'string', 'max:255', function (string $a, mixed $v, \Closure $fail) {
                if (is_string($v) && Playbook::isReservedKey($v)) {
                    $fail('Keys starting with "'.Playbook::RESERVED_KEY_PREFIX.'" are reserved for Lodestar.');
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'mode' => ['nullable', 'in:'.implode(',', Playbook::MODES)],
            'note' => ['nullable', 'string'],
            'base_hash' => ['nullable', 'string', 'max:64'],
            'team_id' => ['required_if:scope,'.Playbook::SCOPE_TEAM, 'integer'],
            'project' => ['required_if:scope,'.Playbook::SCOPE_PROJECT, 'string'],
        ]);

        // A summary is the catalog line for named (on-demand) playbooks, so require
        // it there; phase playbooks compose automatically and aren't catalogued.
        if (! Playbook::isPhase($data['key']) && blank($data['summary'] ?? null)) {
            return Response::error('A summary is required for a named playbook — it\'s the line the main catalog shows so an agent knows when to load it.');
        }

        $user = $this->currentUser($request);

        $owner = $this->resolveOwner($request, $data);
        if ($owner instanceof Response) {
            return $owner; // a tenancy error
        }

        $slot = Playbook::ensureSlot($data['scope'], $owner, $data['key'], $data['title']);
        if (! $slot->canBeAccessedBy($user)) {
            return Response::error('You cannot propose changes to that scope.');
        }

        // Compare-and-swap: if the slot already has versions you must echo the
        // current hash (get it from get_playbook), proving you read what you're
        // editing. A brand-new slot has nothing to read, so the hash is optional.
        $existing = $slot->latestVersion();
        if ($existing !== null && blank($data['base_hash'] ?? null)) {
            return $this->staleResponse($slot, $existing, 'base_hash is required when editing an existing playbook — read the current version first.');
        }

        try {
            // byAi: true → never goes live, regardless of who owns the scope.
            $version = $slot->submitVersion(
                $data['title'], $data['summary'] ?? null, $data['body'], $user,
                byAi: true, note: $data['note'] ?? null, mode: $data['mode'] ?? Playbook::MODE_APPEND,
                expectedHash: $data['base_hash'] ?? null,
            );
        } catch (StalePlaybookHashException $e) {
            return $this->staleResponse($slot, $e->latest, $e->getMessage());
        }

        return Response::json([
            'playbook_id' => $slot->id,
            'version_id' => $version->id,
            'version' => $version->version,
            'status' => $version->status, // always "proposed"
            'hash' => $version->contentHash(), // the new base_hash — chain edits with this
            'note' => 'Recorded as a proposal — a human approver must make it live.',
        ]);
    }

    /**
     * The slot moved since the caller read it (or they never read it): hand back
     * the current content + hash so they re-read, then retry with that base_hash.
     */
    private function staleResponse(Playbook $slot, ?PlaybookVersion $current, string $message): Response
    {
        return Response::json([
            'error' => $message,
            'playbook_id' => $slot->id,
            'current_hash' => $slot->currentHash(),
            'current_version' => $current?->version,
            'current_title' => $current?->title,
            'current_summary' => $current?->summary,
            'current_mode' => $current?->mode,
            'current_body' => $current?->body,
            'note' => 'Re-read the current version above, then resend your proposal with base_hash set to current_hash.',
        ]);
    }

    /** Resolve and tenancy-check the scope owner, or return an error Response. */
    private function resolveOwner(Request $request, array $data): Model|Response|null
    {
        $user = $this->currentUser($request);

        return match ($data['scope']) {
            Playbook::SCOPE_PERSONAL => $user,
            Playbook::SCOPE_TEAM => $user->isInTeam((int) $data['team_id'])
                ? Team::find((int) $data['team_id'])
                : Response::error('No team with that id belongs to you.'),
            Playbook::SCOPE_PROJECT => $this->ownedProject($request, $data['project'])
                ?? Response::error('No project "'.$data['project'].'" belongs to you.'),
            default => Response::error('That scope cannot be proposed to.'),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum([Playbook::SCOPE_PERSONAL, Playbook::SCOPE_TEAM, Playbook::SCOPE_PROJECT])->description('Which layer to propose on: personal, team or project.')->required(),
            'key' => $schema->string()->description('Playbook key: a phase (main/plan/develop/ai_review/merge) or a named key.')->required(),
            'title' => $schema->string()->description('Title for this version.')->required(),
            'summary' => $schema->string()->description('One-line "what / when to use" — shown in the main catalog for named playbooks.'),
            'body' => $schema->string()->description('The proposed prompt body (markdown).')->required(),
            'mode' => $schema->string()->enum(Playbook::MODES)->description('How this version composes: append (default) or overwrite (discards the layers above it). Changing it is part of the proposal.'),
            'note' => $schema->string()->description('Optional message for the approver explaining the change.'),
            'base_hash' => $schema->string()->description('Compare-and-swap token: the `hash` returned by get_playbook for the layer you are editing. REQUIRED when the slot already has versions (proves you read the current version); omit only when creating a brand-new layer. On mismatch the call is rejected with the current content + hash so you can re-read.'),
            'team_id' => $schema->integer()->description('Required when scope=team: the team that owns the layer.'),
            'project' => $schema->string()->description('Required when scope=project: project id or slug.'),
        ];
    }
}
