<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Support;

use Filament\Support\Colors\Color;

/**
 * Resolves a breakdown row's color to a CSS value for a pie slice. A row color
 * is either a Filament color alias (e.g. `danger`, matching the table badge) or
 * a Filament shade array (e.g. `Color::Red`); either way the pie slice and the
 * table badge for the same bucket render the same color.
 */
final class BreakdownColor
{
    /** @var list<array<array-key, string>> */
    private const NEUTRAL_PALETTE = [
        Color::Blue,
        Color::Green,
        Color::Amber,
        Color::Purple,
        Color::Cyan,
        Color::Pink,
        Color::Teal,
        Color::Indigo,
    ];

    /**
     * @param  string|array<array-key, string>  $color
     */
    public static function chart(string|array $color): string
    {
        if (is_array($color)) {
            return $color[500] ?? Color::Gray[400];
        }

        return match ($color) {
            'danger' => Color::Red[500],
            'warning' => Color::Amber[500],
            'info' => Color::Blue[500],
            'success' => Color::Green[500],
            'primary' => Color::Amber[500],
            'secondary' => Color::Gray[300],
            default => Color::Gray[400],
        };
    }

    /**
     * A stable neutral shade array for an arbitrary bucket key, so the same key
     * always maps to the same color across renders.
     *
     * @return array<array-key, string>
     */
    public static function neutral(string $key): array
    {
        $palette = self::NEUTRAL_PALETTE;

        return $palette[crc32($key) % count($palette)];
    }
}
