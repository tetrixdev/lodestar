<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Add or update one section (step) of a task\'s plan-review walkthrough — the plan-side mirror of upsert_review_section. The planning agent builds these alongside the plan so a human can walk it section by section at the plan_review gate. Omit id to append a section; pass id to update one.')]
#[Name('upsert_plan_review_section')]
class UpsertPlanReviewSectionTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'id' => ['nullable', 'integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'focus' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer'],
            'context' => ['nullable', 'string'],
            'checks' => ['nullable', 'array'],
            'checks.*' => ['string'],
            'status' => ['nullable', 'string', 'in:open,signed_off'],
            'note' => ['nullable', 'string'],
        ]);

        $task = $this->ownedTask($request, (int) $data['task_id']);
        if (! $task) {
            return Response::error('No task with that id belongs to you.');
        }

        if (! empty($data['id'])) {
            $section = $task->planReviewSections()->whereKey((int) $data['id'])->first();
            if (! $section) {
                return Response::error('No plan-review section with that id on this task.');
            }
        } else {
            $section = $task->planReviewSections()->make([
                'status' => 'open',
                'position' => (int) $task->planReviewSections()->max('position') + 1,
            ]);
        }

        foreach (['title', 'focus', 'position', 'context', 'checks', 'status', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $section->{$field} = $data[$field];
            }
        }
        $section->save();

        return Response::json([
            'id' => $section->id,
            'task_id' => $task->id,
            'position' => $section->position,
            'created' => $section->wasRecentlyCreated,
            'sections' => $task->planReviewSections()->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task whose plan-review walkthrough this section belongs to.')->required(),
            'id' => $schema->integer()->description('Existing section id to update. Omit to append.'),
            'title' => $schema->string()->description('Section title (required when creating).'),
            'focus' => $schema->string()->description('The slice of the plan this step reviews, e.g. "Data model", "Migration", "MCP surface".'),
            'position' => $schema->integer()->description('Order in the walkthrough. Defaults to the end.'),
            'context' => $schema->string()->description('Prose (markdown) that rebuilds the reviewer\'s context for this step.'),
            'checks' => $schema->array()->description('List of "what to confirm" strings.'),
            'status' => $schema->string()->enum(['open', 'signed_off'])->description('Sign-off state (humans set this in the UI; usually leave as open).'),
            'note' => $schema->string()->description('Optional note / change request.'),
        ];
    }
}
