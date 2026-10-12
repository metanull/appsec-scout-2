<?php

namespace Tests\Fixtures\Mcp;

use App\Mcp\Support\Pagination;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('pagination-test')]
final class PaginationTestTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return Pagination::schema($schema);
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        return Pagination::paginate(
            SoftwareSystem::query()->orderBy('id'),
            $request,
            fn (SoftwareSystem $system): array => ['id' => $system->id],
        );
    }
}
