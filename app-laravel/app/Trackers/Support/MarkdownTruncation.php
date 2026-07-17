<?php

namespace App\Trackers\Support;

final class MarkdownTruncation
{
    public const DEFAULT_MAX_BYTES = 16384;

    public const TRUNCATION_MARKER = '...(truncated)';

    public static function atParagraphBoundary(string $markdown, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        if (strlen($markdown) <= $maxBytes) {
            return $markdown;
        }

        $paragraphs = explode("\n\n", $markdown);
        $valid = '';
        $low = 0;
        $high = count($paragraphs);

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = implode("\n\n", array_slice($paragraphs, 0, $mid));

            if ($candidate !== '') {
                $candidate .= "\n\n" . self::TRUNCATION_MARKER;
            } else {
                $candidate = self::TRUNCATION_MARKER;
            }

            if (strlen($candidate) <= $maxBytes) {
                $valid = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $valid === '' ? self::TRUNCATION_MARKER : $valid;
    }
}
