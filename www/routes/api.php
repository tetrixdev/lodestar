<?php

use App\Http\Controllers\SecretController;
use Illuminate\Support\Facades\Route;

// Token-authed, out-of-MCP endpoints. These are deliberately NOT MCP tools: an
// agent calls them with a side-channel `curl` so the payload (e.g. secret values)
// never enters the MCP/LLM context. Same Sanctum token + `agent` ability as MCP.
Route::middleware(['auth:sanctum', 'abilities:agent'])->group(function () {
    // The project's required secrets, filled with the calling user's own values.
    Route::get('/projects/{project}/secrets', [SecretController::class, 'bundle'])
        ->name('api.projects.secrets');
});
