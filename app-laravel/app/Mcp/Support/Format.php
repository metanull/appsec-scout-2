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

    /** The backing value of an enum-cast attribute (read via getAttribute()), null when unset. */
    public static function enum(mixed $value): ?string
    {
        return match (true) {
            $value instanceof \BackedEnum => (string) $value->value,
            is_string($value) => $value,
            default => null,
        };
    }

    /**
     * The backing values of an enum's cases, for schema enums and validation.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    public static function enumValues(string $enum): array
    {
        return array_map(fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /** @return array<string, mixed> a JSON-cast attribute as an array (empty when unset) */
    public static function arrayAttribute(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
