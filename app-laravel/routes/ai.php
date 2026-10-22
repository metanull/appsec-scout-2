<?php

use App\Mcp\Servers\AppSecScoutServer;
use Laravel\Mcp\Facades\Mcp;

// Stdio only, started per client session with
// `docker compose exec -T -e MCP_USER_EMAIL=... app php -d display_errors=stderr artisan mcp:start appsec-scout`
// (see docs/mcp.md). The HTTP transport is deliberately not exposed.
Mcp::local(AppSecScoutServer::HANDLE, AppSecScoutServer::class);
