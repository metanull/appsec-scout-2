<?php

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Server;

/** Exercises the AppSecTool / Pagination / Scope conventions independently of real tools. */
final class FoundationTestServer extends Server
{
    protected array $tools = [
        GatedTestTool::class,
        AuditingTestTool::class,
        ExplodingTestTool::class,
        ScopeTestTool::class,
        PaginationTestTool::class,
    ];
}
