<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update a deliverable — the optional Project → Deliverable → Task layer. Omit id to create; pass id to update content. A deliverable is a SCOPE (concept/body), NOT a plan — the plan is the set of child tasks the planning phase decomposes it into (each approved per-task). It always enters at backlog (new); its status then DERIVES from its tasks. May also carry scope-level open `questions`.')]
#[Name('upsert_deliverable')]
class UpsertDeliverableTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required_without:id', 'string'],
            'id' => ['nullable', 'integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'base_branch' => ['nullable', 'string', 'max:255'],
            'concept' => ['nullable', 'string'],
            'concept_summary' => ['nullable', 'string', 'required_with:concept'],
            'body' => ['nullable', 'string'],
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'questions' => ['nullable', 'array'],
            'questions.*' => ['string'],
        ]);

        if (! empty($data['id'])) {
            $deliverable = Deliverable::query()
                ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
                ->whereKey((int) $data['id'])
                ->first();
            if (! $deliverable) {
                return Response::error('No deliverable with that id belongs to you.');
            }
        } else {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
            // A deliverable always enters at backlog (new); its status then derives
            // from its tasks once decomposition creates them.
            $status = Deliverable::STATUS_NEW;
            $deliverable = $project->deliverables()->make([
                'status' => $status,
                'position' => (int) $project->deliverables()->where('status', $status)->max('position') + 1,
            ]);
        }

        foreach (['title', 'category', 'base_branch', 'concept', 'concept_summary', 'body', 'body_summary'] as $field) {
            if (array_key_exists($field, $data)) {
                $deliverable->{$field} = $data[$field];
            }
        }
        $deliverable->save();

        // Raise any new open questions (append; the human answers them in the UI).
        if (! empty($data['questions'])) {
            $existing = $deliverable->questions()->pluck('question')->all();
            $pos = (int) $deliverable->questions()->max('position');
            foreach ($data['questions'] as $question) {
                if (! in_array($question, $existing, true)) {
                    $deliverable->questions()->create(['question' => $question, 'position' => ++$pos]);
                }
            }
        }

        return Response::json([
            'id' => $deliverable->id,
            'title' => $deliverable->title,
            'status' => $deliverable->status,
            'open_questions' => $deliverable->questions()->whereNull('answered_at')->count(),
            'created' => $deliverable->wasRecentlyCreated,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (required when creating).'),
            'id' => $schema->integer()->description('Existing deliverable id to update. Omit to create.'),
            'title' => $schema->string()->description('Deliverable title (required when creating).'),
            'category' => $schema->string()->description('Optional grouping prefix.'),
            'base_branch' => $schema->string()->description('The branch the deliverable is cut from and diffed against (e.g. main).'),
            'concept' => $schema->string()->description('The raw goal/scope as the user wrote it. If set you MUST also pass concept_summary.'),
            'concept_summary' => $schema->string()->description('Required when concept is set: a 1–2 sentence TL;DR.'),
            'body' => $schema->string()->description('The refined SCOPE in our format (Why / What — in & out / Done when). A deliverable is a scope, not a plan — the plan is the set of child tasks. If set you MUST also pass body_summary.'),
            'body_summary' => $schema->string()->description('Required when body is set: a 1–2 sentence TL;DR of the scope.'),
            'questions' => $schema->array()->items($schema->string())->description('Open questions for the human (scope-level). Appended (new ones only).'),
        ];
    }
}
