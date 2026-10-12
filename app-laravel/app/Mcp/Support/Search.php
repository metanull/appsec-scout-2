<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** The `search` input shared by MCP list tools: a portable LIKE substring match. */
final class Search
{
    public const MAX_LENGTH = 200;

    public static function schema(JsonSchema $schema, string $fields): Type
    {
        return $schema->string()->max(self::MAX_LENGTH)
            ->description("Substring match on {$fields}.");
    }

    /** @return list<string> validation rules for the `search` input */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:' . self::MAX_LENGTH];
    }

    /** The LIKE pattern for a search term (wildcards escaped), or null when there is nothing to match. */
    public static function like(?string $term): ?string
    {
        $term = trim((string) $term);

        if ($term === '') {
            return null;
        }

        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
    }
}
