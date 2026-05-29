<?php

namespace App\Trackers\Jira;

final class AdfToText
{
    /**
     * Convert an ADF document (as a decoded array) to plain text,
     * preserving all URLs needed for reconciliation.
     *
     * @param  array<string, mixed>  $adf
     */
    public static function toText(array $adf): string
    {
        $parts = self::extractNode($adf);

        return trim(implode(' ', array_filter($parts, fn (string $p): bool => $p !== '')));
    }

    /**
     * Extract all HTTP/HTTPS URLs from an ADF document.
     *
     * @param  array<string, mixed>  $adf
     * @return list<string>
     */
    public static function extractUrls(array $adf): array
    {
        $text = self::toText($adf);

        return self::urlsFromText($text);
    }

    /**
     * Extract HTTP/HTTPS URLs from any text string (Markdown, plain text, ADF-derived text).
     *
     * @return list<string>
     */
    public static function urlsFromText(string $text): array
    {
        preg_match_all('#https?://\S+#', $text, $matches);

        $urls = array_map(fn (string $url): string => rtrim($url, '.,;:\'")'), $matches[0]);

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function extractNode(array $node): array
    {
        $parts = [];
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            $text = (string) ($node['text'] ?? '');

            foreach ((array) ($node['marks'] ?? []) as $mark) {
                if (! is_array($mark)) {
                    continue;
                }

                $markType = (string) ($mark['type'] ?? '');

                if ($markType === 'link') {
                    $href = (string) ($mark['attrs']['href'] ?? '');
                    if ($href !== '') {
                        $parts[] = $href;
                    }
                }
            }

            if ($text !== '') {
                $parts[] = $text;
            }

            return $parts;
        }

        if ($type === 'inlineCard') {
            $url = (string) ($node['attrs']['url'] ?? '');
            if ($url !== '') {
                $parts[] = $url;
            }

            return $parts;
        }

        if ($type === 'mention') {
            return $parts;
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                foreach (self::extractNode($child) as $part) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }
}
