<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Skill;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Propose a change to a skill — a new version of a (scope, key) slot, creating the slot if needed. Scope is "personal" (your own layer), "team" (needs team_id) or "project" (needs project). Your proposal is ALWAYS recorded as PROPOSED and awaits a human approver; an AI can never make a skill live, not even on your own personal layer. System skills are seeded, not proposable here.')]
#[Name('propose_skill_change')]
class ProposeSkillChangeTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'scope' => ['required', 'in:'.Skill::SCOPE_PERSONAL.','.Skill::SCOPE_TEAM.','.Skill::SCOPE_PROJECT],
            'key' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'mode' => ['nullable', 'in:'.implode(',', Skill::MODES)],
            'note' => ['nullable', 'string'],
            'team_id' => ['required_if:scope,'.Skill::SCOPE_TEAM, 'integer'],
            'project' => ['required_if:scope,'.Skill::SCOPE_PROJECT, 'string'],
        ]);

        $user = $this->currentUser($request);

        $owner = $this->resolveOwner($request, $data);
        if ($owner instanceof Response) {
            return $owner; // a tenancy error
        }

        $slot = Skill::ensureSlot($data['scope'], $owner, $data['key'], $data['mode'] ?? Skill::MODE_APPEND, $data['title']);
        if (! $slot->canBeAccessedBy($user)) {
            return Response::error('You cannot propose changes to that scope.');
        }

        // byAi: true → never goes live, regardless of who owns the scope.
        $version = $slot->submitVersion($data['title'], $data['body'], $user, byAi: true, note: $data['note'] ?? null);

        return Response::json([
            'skill_id' => $slot->id,
            'version_id' => $version->id,
            'version' => $version->version,
            'status' => $version->status, // always "proposed"
            'note' => 'Recorded as a proposal — a human approver must make it live.',
        ]);
    }

    /** Resolve and tenancy-check the scope owner, or return an error Response. */
    private function resolveOwner(Request $request, array $data): Model|Response|null
    {
        $user = $this->currentUser($request);

        return match ($data['scope']) {
            Skill::SCOPE_PERSONAL => $user,
            Skill::SCOPE_TEAM => $user->isInTeam((int) $data['team_id'])
                ? Team::find((int) $data['team_id'])
                : Response::error('No team with that id belongs to you.'),
            Skill::SCOPE_PROJECT => $this->ownedProject($request, $data['project'])
                ?? Response::error('No project "'.$data['project'].'" belongs to you.'),
            default => Response::error('That scope cannot be proposed to.'),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum([Skill::SCOPE_PERSONAL, Skill::SCOPE_TEAM, Skill::SCOPE_PROJECT])->description('Which layer to propose on: personal, team or project.')->required(),
            'key' => $schema->string()->description('Skill key: a phase (main/plan/develop/ai_review/merge) or a named key.')->required(),
            'title' => $schema->string()->description('Title for this version.')->required(),
            'body' => $schema->string()->description('The proposed prompt body (markdown).')->required(),
            'mode' => $schema->string()->enum(Skill::MODES)->description('Set only when creating the slot: append (default) or overwrite (discards the layers above it).'),
            'note' => $schema->string()->description('Optional message for the approver explaining the change.'),
            'team_id' => $schema->integer()->description('Required when scope=team: the team that owns the layer.'),
            'project' => $schema->string()->description('Required when scope=project: project id or slug.'),
        ];
    }
}
