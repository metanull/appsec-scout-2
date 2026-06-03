<?php

namespace App\Trackers\Reconciliation;

final class UrlExtractor
{
    /** @return list<string> */
    public static function extractFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $urls = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $httpPos = stripos($text, 'http://', $offset);
            $httpsPos = stripos($text, 'https://', $offset);
            $start = self::nextStart($httpPos, $httpsPos);

            if ($start === null) {
                break;
            }

            $end = $start;
            while ($end < $length && ! self::isTerminator($text[$end])) {
                $end++;
            }

            while ($end > $start && self::isTrailingPunctuation($text[$end - 1])) {
                $end--;
            }

            $candidate = substr($text, $start, $end - $start);
            self::collectIfHttpUrl($candidate, $urls);
            $offset = max($end, $start + 1);
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    public static function extractFromMarkdown(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        $urls = self::extractFromText($markdown);
        $offset = 0;
        $length = strlen($markdown);

        while ($offset < $length) {
            $linkStart = strpos($markdown, '](', $offset);

            if ($linkStart === false) {
                break;
            }

            $urlStart = $linkStart + 2;
            $depth = 1;
            $position = $urlStart;

            while ($position < $length && $depth > 0) {
                $char = $markdown[$position];

                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }

                $position++;
            }

            if ($depth === 0 && $position > $urlStart) {
                $candidate = trim(substr($markdown, $urlStart, $position - $urlStart - 1));
                $candidate = self::stripMarkdownTitle($candidate);

                foreach (self::extractFromText($candidate) as $url) {
                    $urls[] = $url;
                }
            }

            $offset = max($position, $urlStart + 1);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $adf
     * @return list<string>
     */
    public static function extractFromAdf(array $adf): array
    {
        $urls = [];

        self::walkAdfNode($adf, $urls);

        return array_values(array_unique($urls));
    }

    private static function nextStart(int|false $httpPos, int|false $httpsPos): ?int
    {
        if ($httpPos === false && $httpsPos === false) {
            return null;
        }

        if ($httpPos === false) {
            return $httpsPos;
        }

        if ($httpsPos === false) {
            return $httpPos;
        }

        return min($httpPos, $httpsPos);
    }

    private static function isTerminator(string $char): bool
    {
        return ctype_space($char) || in_array($char, ['"', "'", '`', '>', '<', ')'], true);
    }

    private static function isTrailingPunctuation(string $char): bool
    {
        return in_array($char, ['.', ',', ';'], true);
    }

    /**
     * @param  list<string>  $urls
     */
    private static function collectIfHttpUrl(string $candidate, array &$urls): void
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return;
        }

        if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
            return;
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        if (! is_string($scheme)) {
            return;
        }

        $normalizedScheme = strtolower($scheme);
        if (! in_array($normalizedScheme, ['http', 'https'], true)) {
            return;
        }

        $urls[] = $candidate;
    }

    private static function stripMarkdownTitle(string $candidate): string
    {
        if ($candidate === '') {
            return $candidate;
        }

        $spacePos = strpos($candidate, ' ');
        if ($spacePos === false || $spacePos === 0) {
            return $candidate;
        }

        $tail = ltrim(substr($candidate, $spacePos + 1));

        if ($tail !== '' && str_starts_with($tail, '"') && str_ends_with($tail, '"')) {
            return substr($candidate, 0, $spacePos);
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $urls
     */
    private static function walkAdfNode(array $node, array &$urls): void
    {
        $marks = $node['marks'] ?? null;

        if (is_array($marks)) {
            foreach ($marks as $mark) {
                if (! is_array($mark)) {
                    continue;
                }

                if (($mark['type'] ?? null) !== 'link') {
                    continue;
                }

                $href = $mark['attrs']['href'] ?? null;
                if (is_string($href)) {
                    self::collectIfHttpUrl($href, $urls);
                }
            }
        }

        if (($node['type'] ?? null) === 'inlineCard') {
            $cardUrl = $node['attrs']['url'] ?? null;
            if (is_string($cardUrl)) {
                self::collectIfHttpUrl($cardUrl, $urls);
            }
        }

        if (($node['type'] ?? null) === 'text') {
            $text = $node['text'] ?? null;
            if (is_string($text)) {
                foreach (self::extractFromText($text) as $url) {
                    $urls[] = $url;
                }
            }
        }

        $content = $node['content'] ?? null;
        if (! is_array($content)) {
            return;
        }

        foreach ($content as $child) {
            if (is_array($child)) {
                self::walkAdfNode($child, $urls);
            }
        }
    }
}
