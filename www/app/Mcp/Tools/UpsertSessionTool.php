<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update a work-session log entry on one of your projects — a short record of what was done. Omit id to create; pass id to update.')]
#[Name('upsert_session')]
class UpsertSessionTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project' => ['required_without:id', 'string'],
            'id' => ['nullable', 'integer'],
            'title' => ['required_without:id', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // The summary is mandatory whenever the long-form body is set.
            'body_summary' => ['nullable', 'string', 'required_with:body'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        if (! empty($data['id'])) {
            $session = $this->ownedSession($request, (int) $data['id']);
            if (! $session) {
                return Response::error('No work-session with that id belongs to you.');
            }
        } else {
            $project = $this->ownedProject($request, $data['project']);
            if (! $project) {
                return Response::error('No project "'.$data['project'].'" belongs to you.');
            }
            $session = $project->workSessions()->make();
        }

        if (array_key_exists('title', $data)) {
            $session->title = $data['title'];
            $session->slug = $data['slug'] ?? Str::slug($data['title']);
        } elseif (array_key_exists('slug', $data)) {
            $session->slug = $data['slug'];
        }
        foreach (['body', 'body_summary', 'occurred_on'] as $field) {
            if (array_key_exists($field, $data)) {
                $session->{$field} = $data[$field];
            }
        }
        $session->save();

        return Response::json([
            'id' => $session->id,
            'title' => $session->title,
            'created' => $session->wasRecentlyCreated,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (required when creating).'),
            'id' => $schema->integer()->description('Existing work-session id to update. Omit to create.'),
            'title' => $schema->string()->description('Session title (required when creating).'),
            'slug' => $schema->string()->description('Optional slug; defaults from the title.'),
            'body' => $schema->string()->description('Full markdown record of what was done — a log entry, not an essay (1–3 tight paragraphs). Cover: what changed and the outcome; then call out **decisions made**, **open threads** still in flight, and **gotchas the next session must know**. This is the running history a future session reads to orient. If you set this you MUST also pass body_summary.'),
            'body_summary' => $schema->string()->description('Required whenever body is set: a 1–2 sentence scannable TL;DR of the session.'),
            'occurred_on' => $schema->string()->description('Date the work happened (YYYY-MM-DD).'),
        ];
    }
}
