<?php

namespace App\Mcp\Support;

use DateTimeInterface;

/** Value formatting shared by MCP tool payloads. */
final class Format
{
    /** ISO 8601 for a date attribute, null for anything else (unset, null). */
    public static function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /** @return array<string, mixed> a JSON-cast attribute as an array (empty when unset) */
    public static function arrayAttribute(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
