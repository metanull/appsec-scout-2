<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The `search` input shared by MCP list tools: a case-insensitive substring match, applied with
 * App\Database\TextSearch so it behaves the same on every database engine.
 */
final class Search
{
    public const MAX_LENGTH = 200;

    public static function schema(JsonSchema $schema, string $fields): Type
    {
        return $schema->string()->max(self::MAX_LENGTH)
            ->description("Substring match on {$fields}, ignoring case.");
    }

    /** @return list<string> validation rules for the `search` input */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:' . self::MAX_LENGTH];
    }
}
