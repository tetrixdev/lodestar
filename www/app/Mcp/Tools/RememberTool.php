<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\StalePlaybookHashException;
use App\Models\Playbook;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Capture a durable learning so it improves future work. It is appended to a playbook layer (personal by default, or project) as a PROPOSED version — never live until a human approves it in the Playbooks overview. Use this instead of keeping notes to yourself: "always run X before Y", "this project deploys via Z". Optionally link the work_session it arose from.')]
#[Name('remember')]
class RememberTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'learning' => ['required', 'string'],
            'scope' => ['nullable', 'in:'.Playbook::SCOPE_PERSONAL.','.Playbook::SCOPE_PROJECT],
            'key' => ['nullable', 'string', 'max:255'],
            'project' => ['required_if:scope,'.Playbook::SCOPE_PROJECT, 'string'],
            'base_hash' => ['nullable', 'string', 'max:64'],
            'work_session_id' => ['nullable', 'integer'],
        ]);

        $user = $this->currentUser($request);
        $scope = $data['scope'] ?? Playbook::SCOPE_PERSONAL;
        $key = $data['key'] ?? 'main';

        $owner = $scope === Playbook::SCOPE_PROJECT
            ? $this->ownedProject($request, $data['project'])
            : $user;
        if (! $owner instanceof Model) {
            return Response::error('No project "'.($data['project'] ?? '').'" belongs to you.');
        }

        $slot = Playbook::ensureSlot($scope, $owner, $key, ucfirst($scope).' '.$key);
        if (! $slot->canBeAccessedBy($user)) {
            return Response::error('You cannot add learnings to that scope.');
        }

        // Same compare-and-swap as propose_playbook_change: appending to an existing
        // layer means you read its current body, so echo its hash (from get_playbook).
        // A brand-new layer has nothing to read, so the hash is optional there.
        if ($slot->activeVersion()->first() !== null && blank($data['base_hash'] ?? null)) {
            return Response::json([
                'error' => 'base_hash is required when remembering onto an existing layer — read it with get_playbook first.',
                'playbook_id' => $slot->id,
                'current_hash' => $slot->currentHash(),
                'current_body' => $slot->activeVersion?->body,
            ]);
        }

        // Append the learning to the layer's current body — proposed, never live.
        $current = trim((string) ($slot->activeVersion?->body ?? ''));
        $body = $current === '' ? '- '.$data['learning'] : $current."\n- ".$data['learning'];

        $session = ! empty($data['work_session_id'])
            ? $this->ownedSession($request, (int) $data['work_session_id'])
            : null;

        try {
            $version = $slot->propose(
                $slot->activeVersion?->title ?? $slot->title,
                $slot->activeVersion?->summary, // carry forward the layer's summary
                $body,
                $user,
                byAi: true,
                note: 'Remembered: '.$data['learning'],
                workSessionId: $session?->id,
                mode: $slot->activeVersion?->mode ?? Playbook::MODE_APPEND,
                expectedHash: $data['base_hash'] ?? null,
            );
        } catch (StalePlaybookHashException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'playbook_id' => $slot->id,
                'current_hash' => $slot->currentHash(),
                'current_body' => $slot->activeVersion?->body,
            ]);
        }

        return Response::json([
            'playbook_id' => $slot->id,
            'version_id' => $version->id,
            'status' => $version->status, // proposed
            'hash' => $version->contentHash(), // the new base_hash
            'note' => 'Captured as a proposal on the '.$scope.' "'.$key.'" layer — approve it in Lodestar → Playbooks to make it live.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'learning' => $schema->string()->description('The durable lesson to remember, one sentence.')->required(),
            'scope' => $schema->string()->enum([Playbook::SCOPE_PERSONAL, Playbook::SCOPE_PROJECT])->description('Where it belongs: personal (default) or project.'),
            'key' => $schema->string()->description('Playbook layer to append to (default "main").'),
            'base_hash' => $schema->string()->description('Compare-and-swap token from get_playbook for this layer. REQUIRED when the layer already exists (proves you read it); omit only for a brand-new layer.'),
            'project' => $schema->string()->description('Required when scope=project: project id or slug.'),
            'work_session_id' => $schema->integer()->description('Optional work-session this learning arose from (provenance).'),
        ];
    }
}
