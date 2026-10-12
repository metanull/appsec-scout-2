<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('scope-test')]
final class ScopeTestTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return Scope::schema($schema);
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        $scope = Scope::fromRequest($request);

        return Response::structured([
            'system_ids' => $scope->systems()->pluck('id')->all(),
        ]);
    }
}
