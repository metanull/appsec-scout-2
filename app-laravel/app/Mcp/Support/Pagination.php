<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Page/per_page inputs and a compact paginated payload shared by every MCP list tool, so a
 * client never pulls an unbounded result set into its context.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 50;

    /** @return array<string, Type> */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)
                ->description('1-based page number.'),
            'per_page' => $schema->integer()->min(1)->max(self::MAX_PER_PAGE)->default(self::DEFAULT_PER_PAGE)
                ->description('Rows per page (1-' . self::MAX_PER_PAGE . ').'),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $mapRow
     */
    public static function paginate(Builder $query, Request $request, callable $mapRow): ResponseFactory
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return Response::structured([
            'items' => array_map($mapRow, $paginator->items()),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
