<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentTokenController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverableController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\McpReferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectToolController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecretController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PlaybookController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('board') : view('welcome');
});

// The unified board is the authenticated landing (task #69). The old dashboard
// inbox is folded into the board's "needs you" strip; /dashboard now redirects
// here (its controller/views stay dormant until #70 deletes them).
Route::get('/dashboard', fn () => redirect()->route('board'))
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/board', [BoardController::class, 'index'])->name('board');

    Route::get('/search', [SearchController::class, 'index'])->name('search');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/embeddings/test-key', [SettingsController::class, 'testKey'])->name('settings.embeddings.test-key');
    Route::post('/settings/embeddings/resync', [SettingsController::class, 'resync'])->name('settings.embeddings.resync');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/approvers', [ProjectController::class, 'addApprover'])->name('projects.approvers.add');
    Route::delete('/projects/{project}/approvers/{user}', [ProjectController::class, 'removeApprover'])->name('projects.approvers.remove');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');
    // Project secrets — required-keys manifest (approvers) + each user's own values.
    Route::get('/projects/{project}/secrets', [SecretController::class, 'index'])->name('secrets.index');
    Route::post('/projects/{project}/secrets/requirements', [SecretController::class, 'storeRequirement'])->name('secrets.requirements.store');
    Route::delete('/projects/{project}/secrets/requirements/{key}', [SecretController::class, 'destroyRequirement'])->name('secrets.requirements.destroy');
    Route::post('/projects/{project}/secrets/values', [SecretController::class, 'storeValue'])->name('secrets.values.store');
    Route::delete('/projects/{project}/secrets/values/{secret}', [SecretController::class, 'destroyValue'])->name('secrets.values.destroy');

    // Project tools — programs to install + commands to provide for the agent.
    Route::get('/projects/{project}/tools', [ProjectToolController::class, 'index'])->name('tools.index');
    Route::post('/projects/{project}/tools', [ProjectToolController::class, 'store'])->name('tools.store');
    Route::delete('/projects/{project}/tools/{tool}', [ProjectToolController::class, 'destroy'])->name('tools.destroy');

    Route::get('/projects/{project}/repositories', [RepositoryController::class, 'index'])->name('repositories.index');
    Route::post('/projects/{project}/repositories', [RepositoryController::class, 'store'])->name('repositories.store');
    Route::delete('/projects/{project}/repositories/{repository}', [RepositoryController::class, 'destroy'])->name('repositories.destroy');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
    Route::patch('/tasks/{task}/release', [TaskController::class, 'release'])->name('tasks.release');
    Route::patch('/tasks/{task}/plan-decision', [TaskController::class, 'planDecision'])->name('tasks.plan-decision');

    // Deliverables — the optional task-grouping layer + its funnel lifecycle.
    Route::post('/projects/{project}/deliverables', [DeliverableController::class, 'store'])->name('deliverables.store');
    Route::get('/deliverables/{deliverable}', [DeliverableController::class, 'show'])->name('deliverables.show');
    Route::patch('/deliverables/{deliverable}', [DeliverableController::class, 'update'])->name('deliverables.update');
    Route::post('/deliverables/{deliverable}/tasks', [DeliverableController::class, 'addTask'])->name('deliverables.tasks.store');
    Route::post('/deliverables/{deliverable}/questions', [DeliverableController::class, 'addQuestion'])->name('deliverables.questions.store');
    Route::patch('/deliverables/{deliverable}/questions/{question}', [DeliverableController::class, 'answerQuestion'])->name('deliverables.questions.answer');

    // Teams — shared projects + approval rights. Membership is owner-managed.
    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    Route::post('/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.add');
    Route::patch('/teams/{team}/members/{user}', [TeamController::class, 'updateMember'])->name('teams.members.update');
    Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember'])->name('teams.members.remove');

    // "Connect a coding agent" — per-machine MCP tokens.
    Route::get('/settings/agent-tokens', [AgentTokenController::class, 'index'])->name('agent-tokens.index');
    Route::post('/settings/agent-tokens', [AgentTokenController::class, 'store'])->name('agent-tokens.store');
    Route::delete('/settings/agent-tokens/{token}', [AgentTokenController::class, 'destroy'])->name('agent-tokens.destroy');

    // GitHub connections — link accounts/tokens used to read repos.
    Route::get('/settings/github', [GithubConnectionController::class, 'index'])->name('github.index');
    Route::post('/settings/github', [GithubConnectionController::class, 'store'])->name('github.store');
    Route::delete('/settings/github/{connection}', [GithubConnectionController::class, 'destroy'])->name('github.destroy');

    // Playbooks — view the composed effective prompt per phase, and change control:
    // propose a version (anyone in scope), approve/reject (assigned approvers),
    // toggle a layer's append/overwrite mode (approvers).
    // MCP reference — every tool, its params and example output (read-only).
    Route::get('/settings/mcp', [McpReferenceController::class, 'index'])->name('mcp.reference');

    Route::get('/settings/playbooks', [PlaybookController::class, 'index'])->name('playbooks.index');
    Route::get('/settings/playbooks/{playbook}', [PlaybookController::class, 'show'])->name('playbooks.show');
    Route::post('/settings/playbooks/propose', [PlaybookController::class, 'propose'])->name('playbooks.propose');
    Route::post('/settings/playbooks/versions/{version}/approve', [PlaybookController::class, 'approve'])->name('playbooks.versions.approve');
    Route::post('/settings/playbooks/versions/{version}/approve-edits', [PlaybookController::class, 'approveWithEdits'])->name('playbooks.versions.approveEdits');
    Route::post('/settings/playbooks/versions/{version}/reject', [PlaybookController::class, 'reject'])->name('playbooks.versions.reject');

    // Work sessions — a project's running history of what was done.
    Route::get('/projects/{project}/sessions', [WorkSessionController::class, 'index'])->name('work-sessions.index');
    Route::get('/projects/{project}/sessions/create', [WorkSessionController::class, 'create'])->name('work-sessions.create');
    Route::post('/projects/{project}/sessions', [WorkSessionController::class, 'store'])->name('work-sessions.store');
    Route::get('/sessions/{workSession}', [WorkSessionController::class, 'show'])->name('work-sessions.show');

    Route::get('/projects/{project}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
    // Server-rendered file viewer fragment (modal fetches this). Scoped so the
    // file must belong to the review in the URL.
    Route::get('/reviews/{review}/files/{file}', [ReviewController::class, 'file'])
        ->scopeBindings()->name('reviews.files.show');
    Route::post('/reviews/{review}/assign', [ReviewController::class, 'assign'])->name('reviews.assign');
    Route::post('/reviews/{review}/unassign', [ReviewController::class, 'unassign'])->name('reviews.unassign');
    Route::patch('/reviews/{review}/sections/{section}', [ReviewController::class, 'updateSection'])->name('reviews.sections.update');
    Route::patch('/reviews/{review}/sections/{section}/findings/{finding}', [ReviewController::class, 'updateFinding'])->name('reviews.findings.update');
    Route::post('/reviews/{review}/conclude', [ReviewController::class, 'conclude'])->name('reviews.conclude');
});

require __DIR__.'/auth.php';
