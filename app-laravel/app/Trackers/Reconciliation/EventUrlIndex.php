<?php

namespace App\Trackers\Reconciliation;

use App\Models\SecurityEvent;

final class EventUrlIndex
{
    /** @var array<string, list<int>> */
    private array $index;

    /** @param array<string, list<int>> $index */
    private function __construct(array $index)
    {
        $this->index = $index;
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     */
    public static function build(iterable $events): self
    {
        $index = [];

        foreach ($events as $event) {
            $eventId = (int) $event->id;

            foreach (self::urlsForEvent($event) as $url) {
                $normalized = self::normalizeUrl($url);

                if ($normalized === null) {
                    continue;
                }

                $index[$normalized] ??= [];

                if (! in_array($eventId, $index[$normalized], true)) {
                    $index[$normalized][] = $eventId;
                }
            }
        }

        return new self($index);
    }

    /** @return list<int> */
    public function findExact(string $url): array
    {
        $normalized = self::normalizeUrl($url);

        if ($normalized === null) {
            return [];
        }

        return $this->index[$normalized] ?? [];
    }

    /** @return list<int> */
    public function findByPrefix(string $url): array
    {
        $normalized = self::normalizeUrl($url);

        if ($normalized === null) {
            return [];
        }

        $prefix = rtrim($normalized, '/') . '/';
        $matches = [];

        foreach ($this->index as $indexedUrl => $eventIds) {
            if (! str_starts_with($indexedUrl, $prefix)) {
                continue;
            }

            foreach ($eventIds as $eventId) {
                if (! in_array($eventId, $matches, true)) {
                    $matches[] = $eventId;
                }
            }
        }

        return $matches;
    }

    /** @return list<int> */
    public function findAll(string $url): array
    {
        $combined = array_merge(
            $this->findExact($url),
            $this->findByPrefix($url),
        );

        return array_values(array_unique($combined));
    }

    /** @return list<string> */
    private static function urlsForEvent(SecurityEvent $event): array
    {
        $urls = [];

        foreach ([(string) ($event->url ?? ''), (string) ($event->version_control_url ?? '')] as $candidate) {
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }

        $metadata = self::arrayFromMixed($event->getAttribute('metadata'));

        $links = $metadata['links'] ?? null;
        if (is_array($links)) {
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $linkUrl = $link['url'] ?? null;
                if (is_string($linkUrl) && trim($linkUrl) !== '') {
                    $urls[] = $linkUrl;
                }
            }
        }

        $sourceData = self::arrayFromMixed($event->getAttribute('source_data'));
        $alertUri = $sourceData['alertUri'] ?? ($metadata['azdo']['alertUri'] ?? null);

        if (is_string($alertUri) && trim($alertUri) !== '') {
            $urls[] = $alertUri;

            foreach (self::synthesizeAzdoPortalUrls($alertUri) as $synthesizedUrl) {
                $urls[] = $synthesizedUrl;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayFromMixed(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private static function synthesizeAzdoPortalUrls(string $alertUri): array
    {
        $parts = parse_url($alertUri);

        if (! is_array($parts)) {
            return [];
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || ! str_starts_with(strtolower($host), 'advsec.')) {
            return [];
        }

        $path = $parts['path'] ?? null;

        if (! is_string($path) || trim($path) === '') {
            return [];
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

        if (count($segments) < 8) {
            return [];
        }

        if (strtolower($segments[2]) !== '_apis' || strtolower($segments[4]) !== 'repositories') {
            return [];
        }

        $organization = $segments[0];
        $projectGuid = $segments[1];
        $repoGuid = $segments[5];
        $alertId = $segments[7];

        $alertUrl = sprintf(
            'https://dev.azure.com/%s/%s/_git/%s/alerts/%s',
            $organization,
            $projectGuid,
            $repoGuid,
            $alertId,
        );

        $repoRootUrl = sprintf(
            'https://dev.azure.com/%s/%s/_git/%s',
            $organization,
            $projectGuid,
            $repoGuid,
        );

        return [$alertUrl, $repoRootUrl];
    }

    private static function normalizeUrl(string $url): ?string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return null;
        }

        $withoutFragment = explode('#', $trimmed, 2)[0];

        if ($withoutFragment === '') {
            return null;
        }

        if (! filter_var($withoutFragment, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($withoutFragment, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return null;
        }

        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return rtrim(strtolower($withoutFragment), '/');
    }
}
