<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        $page = self::page($query, $request);

        return self::respond($page, array_map($mapRow, $page->items()));
    }

    /**
     * The requested page of models, for tools that enrich a page before rendering it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function page(Builder $query, Request $request): LengthAwarePaginator
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        return $query->paginate(
            perPage: (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($validated['page'] ?? 1),
        );
    }

    /**
     * @param  LengthAwarePaginator<int, covariant Model>  $page
     * @param  array<int, array<string, mixed>>  $items  the page's rows, already rendered
     * @param  array<string, mixed>  $extra  top-level keys placed before the page (e.g. the parent record)
     */
    public static function respond(LengthAwarePaginator $page, array $items, array $extra = []): ResponseFactory
    {
        return Response::structured([
            ...$extra,
            'items' => array_values($items),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'has_more' => $page->hasMorePages(),
        ]);
    }
}
