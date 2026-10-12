<?php

namespace Tests\Fixtures\Mcp;

use App\Audit\Recorder;
use App\Mcp\Tools\AppSecTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('auditing-test')]
final class AuditingTestTool extends AppSecTool
{
    public function __construct(private readonly Recorder $recorder) {}

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        $this->recorder->recordAdminAction('mcp_test_action', ['value' => 1]);

        return Response::text('audited');
    }
}
