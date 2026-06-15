<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PersonalSecret;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Project secrets (task #54): a project declares the KEYS it needs (a manifest,
 * managed by approvers); each user provides their own VALUES (encrypted). The
 * agent imports its user's values via `bundle()` — an out-of-MCP endpoint, so the
 * values never enter the LLM channel.
 */
class SecretController extends Controller
{
    /** Manage page: the manifest + this user's values (provided / missing). */
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $requirements = $project->secretRequirements()->orderBy('key')->get();
        $mine = $request->user()->personalSecrets()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->get()
            ->keyBy('key');

        return view('projects.secrets', [
            'project' => $project,
            'requirements' => $requirements,
            'mine' => $mine,
            'canManage' => $project->canApprovePrompts($request->user()),
        ]);
    }

    /** Approver adds a required key to the manifest. */
    public function storeRequirement(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->canApprovePrompts($request->user()), 403);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_secret' => ['nullable', 'boolean'],
        ]);

        $project->secretRequirements()->updateOrCreate(
            ['key' => $data['key']],
            ['description' => $data['description'] ?? null, 'is_secret' => (bool) ($data['is_secret'] ?? true)],
        );

        return back()->with('status', 'requirement-saved');
    }

    /** Approver removes a required key. */
    public function destroyRequirement(Request $request, Project $project, string $key): RedirectResponse
    {
        abort_unless($project->canApprovePrompts($request->user()), 403);

        $project->secretRequirements()->where('key', $key)->delete();

        return back()->with('status', 'requirement-removed');
    }

    /** The current user sets their own value for a key (optionally project-scoped). */
    public function storeValue(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string'],
            'project_scoped' => ['nullable', 'boolean'],
        ]);

        $request->user()->personalSecrets()->updateOrCreate(
            ['key' => $data['key'], 'project_id' => ($data['project_scoped'] ?? false) ? $project->id : null],
            ['value' => $data['value']],
        );

        return back()->with('status', 'value-saved');
    }

    /** The current user clears one of their own values. */
    public function destroyValue(Request $request, Project $project, PersonalSecret $secret): RedirectResponse
    {
        abort_unless($secret->user_id === $request->user()->id, 403);

        $secret->delete();

        return back()->with('status', 'value-removed');
    }

    /**
     * The out-of-MCP bundle: the calling user's values for this project's required
     * keys, as `.env` lines, plus `# missing:` comments for unprovided keys. Token
     * auth (Bearer), never the MCP/LLM channel. The agent writes this to a file.
     */
    public function bundle(Request $request, Project $project): Response
    {
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $required = $project->secretRequirements()->orderBy('key')->get();
        $lines = [];
        $missing = [];

        foreach ($required as $req) {
            $secret = $request->user()->personalSecrets()
                ->where('key', $req->key)
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
                ->orderByRaw('project_id IS NULL') // a project-scoped value wins over the global one
                ->first();

            if ($secret) {
                $lines[] = $req->key.'='.$secret->value;
            } else {
                $missing[] = $req->key;
            }
        }

        $body = implode("\n", $lines);
        if ($missing !== []) {
            $body .= "\n# missing: ".implode(', ', $missing)
                ."\n# Provide these in Lodestar → Project → Secrets, then re-run.";
        }

        return response($body."\n", $missing === [] ? 200 : 409)
            ->header('Content-Type', 'text/plain');
    }
}
