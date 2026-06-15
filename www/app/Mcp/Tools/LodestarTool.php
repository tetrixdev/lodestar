<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every Lodestar MCP tool. Holds the tenancy helpers: a tool only ever
 * sees data reachable from the token's user, exactly as the web controllers
 * only ever see the signed-in user's data. The Sanctum middleware on the route
 * guarantees `$request->user()` is set before any tool runs.
 */
abstract class LodestarTool extends Tool
{
    protected function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * A project the token's user can reach (owner OR a member of its team),
     * addressed by numeric id or slug. The team-aware access rule lives on the
     * Project model; we resolve everything through it so tenancy is identical to
     * the web controllers.
     */
    protected function ownedProject(Request $request, int|string $ref): ?Project
    {
        return Project::accessibleBy($this->currentUser($request))
            ->where(is_numeric($ref) ? 'id' : 'slug', $ref)
            ->first();
    }

    /** A task whose project the token's user can reach (owner or team). */
    protected function ownedTask(Request $request, int $id): ?Task
    {
        return Task::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey($id)
            ->first();
    }

    /** A review whose project the token's user can reach (owner or team). */
    protected function ownedReview(Request $request, int $id): ?Review
    {
        return Review::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey($id)
            ->first();
    }

    /** A work-session whose project the token's user can reach (owner or team). */
    protected function ownedSession(Request $request, int $id): ?WorkSession
    {
        return WorkSession::query()
            ->whereHas('project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->whereKey($id)
            ->first();
    }
}
