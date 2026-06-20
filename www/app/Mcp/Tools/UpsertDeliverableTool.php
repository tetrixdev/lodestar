<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Deliverable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update a deliverable — the optional Project → Deliverable → Task layer. Omit id to create; pass id to update content. The planning phase rewrites the raw `concept` into `body` (the spec), writes the `plan`, and raises open `questions` (every question must be answered by a human before the plan can be approved). A deliverable with a plan enters at plan_review; a bare concept at new. Lifecycle moves go through advance_deliverable, not here.')]
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
            'plan' => ['nullable', 'string'],
            'plan_summary' => ['nullable', 'string', 'required_with:plan'],
            'questions' => ['nullable', 'array'],
            'questions.*' => ['string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [
                Deliverable::STATUS_NEW,
                Deliverable::STATUS_READY_FOR_PLANNING,
                Deliverable::STATUS_PLAN_REVIEW,
            ])],
        ]);

        $hasPlan = ! empty($data['plan'] ?? null);
        if (($data['status'] ?? null) === Deliverable::STATUS_PLAN_REVIEW && ! $hasPlan) {
            return Response::error('A deliverable can only enter at plan_review when it carries a plan — pass `plan` (and `plan_summary`), or use new / ready_for_planning.');
        }

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
            $status = $data['status'] ?? ($hasPlan ? Deliverable::STATUS_PLAN_REVIEW : Deliverable::STATUS_NEW);
            $deliverable = $project->deliverables()->make([
                'status' => $status,
                'position' => (int) $project->deliverables()->where('status', $status)->max('position') + 1,
            ]);
        }

        foreach (['title', 'category', 'base_branch', 'concept', 'concept_summary', 'body', 'body_summary', 'plan', 'plan_summary'] as $field) {
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
            'concept' => $schema->string()->description('The raw goal as the user wrote it. If set you MUST also pass concept_summary.'),
            'concept_summary' => $schema->string()->description('Required when concept is set: a 1–2 sentence TL;DR.'),
            'body' => $schema->string()->description('The rewritten spec in our format (Why / What / Done when). If set you MUST also pass body_summary.'),
            'body_summary' => $schema->string()->description('Required when body is set: a 1–2 sentence TL;DR.'),
            'plan' => $schema->string()->description('The planning artifact that decomposes into child tasks. If set you MUST also pass plan_summary.'),
            'plan_summary' => $schema->string()->description('Required when plan is set: a 1–2 sentence TL;DR.'),
            'questions' => $schema->array()->items($schema->string())->description('Open questions for the human; every one must be answered before the plan can be approved. Appended (new ones only).'),
            'status' => $schema->string()->enum([
                Deliverable::STATUS_NEW,
                Deliverable::STATUS_READY_FOR_PLANNING,
                Deliverable::STATUS_PLAN_REVIEW,
            ])->description('Entry state on create (decide LAST). A deliverable WITH a plan defaults to plan_review; a bare concept to new. Ignored on update — use advance_deliverable.'),
        ];
    }
}
