<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Tools\AppSecTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use RuntimeException;

#[Name('exploding-test')]
final class ExplodingTestTool extends AppSecTool
{
    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        throw new RuntimeException('Upstream exploded');
    }
}
