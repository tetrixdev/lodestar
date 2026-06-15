<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Capture a durable learning so it improves future work. It is appended to a skill layer (personal by default, or project) as a PROPOSED version — never live until a human approves it in the Skills overview. Use this instead of keeping notes to yourself: "always run X before Y", "this project deploys via Z". Optionally link the work_session it arose from.')]
#[Name('remember')]
class RememberTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'learning' => ['required', 'string'],
            'scope' => ['nullable', 'in:'.Skill::SCOPE_PERSONAL.','.Skill::SCOPE_PROJECT],
            'key' => ['nullable', 'string', 'max:255'],
            'project' => ['required_if:scope,'.Skill::SCOPE_PROJECT, 'string'],
            'work_session_id' => ['nullable', 'integer'],
        ]);

        $user = $this->currentUser($request);
        $scope = $data['scope'] ?? Skill::SCOPE_PERSONAL;
        $key = $data['key'] ?? 'main';

        $owner = $scope === Skill::SCOPE_PROJECT
            ? $this->ownedProject($request, $data['project'])
            : $user;
        if (! $owner instanceof Model) {
            return Response::error('No project "'.($data['project'] ?? '').'" belongs to you.');
        }

        $slot = Skill::ensureSlot($scope, $owner, $key, ucfirst($scope).' '.$key);
        if (! $slot->canBeAccessedBy($user)) {
            return Response::error('You cannot add learnings to that scope.');
        }

        // Append the learning to the layer's current body — proposed, never live.
        $current = trim((string) ($slot->activeVersion?->body ?? ''));
        $body = $current === '' ? '- '.$data['learning'] : $current."\n- ".$data['learning'];

        $session = ! empty($data['work_session_id'])
            ? $this->ownedSession($request, (int) $data['work_session_id'])
            : null;

        $version = $slot->propose(
            $slot->activeVersion?->title ?? $slot->title,
            $slot->activeVersion?->summary, // carry forward the layer's summary
            $body,
            $user,
            byAi: true,
            note: 'Remembered: '.$data['learning'],
            workSessionId: $session?->id,
            mode: $slot->activeVersion?->mode ?? Skill::MODE_APPEND,
        );

        return Response::json([
            'skill_id' => $slot->id,
            'version_id' => $version->id,
            'status' => $version->status, // proposed
            'note' => 'Captured as a proposal on the '.$scope.' "'.$key.'" layer — approve it in Lodestar → Skills to make it live.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'learning' => $schema->string()->description('The durable lesson to remember, one sentence.')->required(),
            'scope' => $schema->string()->enum([Skill::SCOPE_PERSONAL, Skill::SCOPE_PROJECT])->description('Where it belongs: personal (default) or project.'),
            'key' => $schema->string()->description('Skill layer to append to (default "main").'),
            'project' => $schema->string()->description('Required when scope=project: project id or slug.'),
            'work_session_id' => $schema->integer()->description('Optional work-session this learning arose from (provenance).'),
        ];
    }
}
