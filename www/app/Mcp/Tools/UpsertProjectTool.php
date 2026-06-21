<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Create or update one of your projects. Omit id to create; pass id to update an existing project you own. slug is unique per user and defaults from the name.')]
#[Name('upsert_project')]
class UpsertProjectTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required_without:id', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'description' => ['nullable', 'string'],
            'primary_goal' => ['nullable', 'string'],
            'stack' => ['nullable', 'string', 'max:255'],
            'repos' => ['nullable', 'array'],
        ]);

        $user = $this->currentUser($request);

        if (! empty($data['id'])) {
            $project = $this->ownedProject($request, (int) $data['id']);
            if (! $project) {
                return Response::error('No project with that id belongs to you.');
            }
        } else {
            $project = $user->projects()->make();
        }

        if (isset($data['name'])) {
            $project->name = $data['name'];
        }
        // Resolve a slug on create (or when explicitly given), unique per user.
        $slug = $data['slug'] ?? ($project->slug ?: Str::slug($project->name));
        $project->slug = $this->uniqueSlug($request, $slug, $project->id);

        foreach (['description', 'primary_goal', 'stack', 'repos'] as $field) {
            if (array_key_exists($field, $data)) {
                $project->{$field} = $data[$field];
            }
        }

        $project->user()->associate($user);
        $project->save();

        return Response::json([
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'created' => $project->wasRecentlyCreated,
        ]);
    }

    /** Suffix the slug with -2, -3 … until it's free for this user. */
    private function uniqueSlug(Request $request, string $base, ?int $ignoreId): string
    {
        $user = $this->currentUser($request);
        $slug = $base;
        $n = 1;

        while ($user->projects()->where('slug', $slug)->whereKeyNot($ignoreId ?? 0)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Existing project id to update. Omit to create.'),
            'name' => $schema->string()->description('Project name (required when creating).'),
            'slug' => $schema->string()->description('URL slug, unique per user. Defaults from the name.'),
            'description' => $schema->string()->description('Optional description.'),
            'primary_goal' => $schema->string()->description('Optional primary goal.'),
            'stack' => $schema->string()->description('Technology-stack tag, e.g. "laravel" — drives framework structure steering in the build/review playbooks.'),
            'repos' => $schema->array()->description('Optional list of {name, url} repo objects.'),
        ];
    }
}
