<?php

use App\Http\Controllers\AgentTokenController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');
    Route::get('/projects/{project}/repositories', [RepositoryController::class, 'index'])->name('repositories.index');
    Route::post('/projects/{project}/repositories', [RepositoryController::class, 'store'])->name('repositories.store');
    Route::delete('/projects/{project}/repositories/{repository}', [RepositoryController::class, 'destroy'])->name('repositories.destroy');
    Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
    Route::patch('/tasks/{task}/release', [TaskController::class, 'release'])->name('tasks.release');

    // "Connect a coding agent" — per-machine MCP tokens.
    Route::get('/settings/agent-tokens', [AgentTokenController::class, 'index'])->name('agent-tokens.index');
    Route::post('/settings/agent-tokens', [AgentTokenController::class, 'store'])->name('agent-tokens.store');
    Route::delete('/settings/agent-tokens/{token}', [AgentTokenController::class, 'destroy'])->name('agent-tokens.destroy');

    // GitHub connections — link accounts/tokens used to read repos.
    Route::get('/settings/github', [GithubConnectionController::class, 'index'])->name('github.index');
    Route::post('/settings/github', [GithubConnectionController::class, 'store'])->name('github.store');
    Route::delete('/settings/github/{connection}', [GithubConnectionController::class, 'destroy'])->name('github.destroy');

    // Skills — view the system prompts, duplicate to a fork, edit the fork.
    Route::get('/settings/skills', [SkillController::class, 'index'])->name('skills.index');
    Route::post('/settings/skills/{skill}/duplicate', [SkillController::class, 'duplicate'])->name('skills.duplicate');
    Route::get('/settings/skills/{skill}/edit', [SkillController::class, 'edit'])->name('skills.edit');
    Route::patch('/settings/skills/{skill}', [SkillController::class, 'update'])->name('skills.update');
    Route::delete('/settings/skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');

    Route::get('/projects/{project}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
    Route::post('/reviews/{review}/assign', [ReviewController::class, 'assign'])->name('reviews.assign');
    Route::post('/reviews/{review}/unassign', [ReviewController::class, 'unassign'])->name('reviews.unassign');
    Route::patch('/reviews/{review}/sections/{section}', [ReviewController::class, 'updateSection'])->name('reviews.sections.update');
    Route::patch('/reviews/{review}/sections/{section}/findings/{finding}', [ReviewController::class, 'updateFinding'])->name('reviews.findings.update');
    Route::post('/reviews/{review}/conclude', [ReviewController::class, 'conclude'])->name('reviews.conclude');
});

require __DIR__.'/auth.php';
