<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Tools\AppSecTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('gated-test')]
final class GatedTestTool extends AppSecTool
{
    protected function requiredPermissions(): array
    {
        return ['admin.queue'];
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        return Response::text('gated tool ran');
    }
}
