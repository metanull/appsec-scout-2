<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Title('Who am I')]
#[Description('Returns the AppSec Scout user this MCP server acts as, with its roles and permissions. Every other tool runs as this user and is limited by these permissions.')]
#[IsReadOnly]
final class WhoAmITool extends AppSecTool
{
    protected function run(Request $request, User $actor): ResponseFactory
    {
        return Response::structured([
            'id' => $actor->id,
            'name' => $actor->name,
            'email' => $actor->email,
            'roles' => $actor->getRoleNames()->values()->all(),
            'permissions' => $actor->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ]);
    }
}
