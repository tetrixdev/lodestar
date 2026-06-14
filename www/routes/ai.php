<?php

use App\Mcp\Servers\LodestarServer;
use Laravel\Mcp\Facades\Mcp;

// The Lodestar MCP server. Sanctum-authed: clients send a per-machine token as
// `Authorization: Bearer <token>` (minted in the web UI under "Connect a coding
// agent"). Every tool resolves its tenant from that token's user.
Mcp::web('/mcp', LodestarServer::class)
    ->middleware(['auth:sanctum', 'abilities:agent']);
