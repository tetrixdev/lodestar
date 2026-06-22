<?php

use App\Http\Controllers\ProjectToolController;
use App\Http\Controllers\ReviewAttachmentController;
use App\Http\Controllers\SecretController;
use Illuminate\Support\Facades\Route;

// Token-authed, out-of-MCP endpoints. These are deliberately NOT MCP tools: an
// agent calls them with a side-channel `curl` so the payload (e.g. secret values)
// never enters the MCP/LLM context. Same Sanctum token + `agent` ability as MCP.
Route::middleware(['auth:sanctum', 'abilities:agent'])->group(function () {
    // The project's required secrets, filled with the calling user's own values.
    Route::get('/projects/{project}/secrets', [SecretController::class, 'bundle'])
        ->name('api.projects.secrets');

    // The project's tools manifest (programs to install + command scripts).
    Route::get('/projects/{project}/tools', [ProjectToolController::class, 'manifest'])
        ->name('api.projects.tools');

    // The agent reports which tools it verified/installed.
    Route::post('/projects/{project}/tools/status', [ProjectToolController::class, 'reportStatus'])
        ->name('api.projects.tools.status');

    // A human's review attachment, fetched by an agent with its Bearer token so
    // the file bytes never enter the MCP/LLM channel (project-access gated).
    Route::get('/review-attachments/{attachment}', [ReviewAttachmentController::class, 'show'])
        ->name('api.review-attachments.show');
});
