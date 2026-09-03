<?php

namespace App\Trackers\Reconciliation;

use App\Models\SecurityEvent;
use App\Sources\Context\SourceContextFacts;

/**
 * Lookup index used by reconciliation to resolve a URL found in a tracker issue body
 * back to the security events it identifies.
 *
 * Matching is deterministic: only URLs that identify a single alert are indexed, and a
 * candidate URL matches either literally or through the canonical Azure DevOps alert
 * identity. Container URLs (project roots, repository roots) and shared URLs (source
 * files, rule documentation, advisories) are deliberately never indexed.
 */
final class EventUrlIndex
{
    /**
     * @param  array<string, list<int>>  $index
     * @param  array<string, list<int>>  $alertKeyIndex
     */
    private function __construct(
        private array $index,
        private array $alertKeyIndex,
    ) {}

    /**
     * @param  iterable<SecurityEvent>  $events
     */
    public static function build(iterable $events): self
    {
        $index = [];
        $alertKeyIndex = [];

        foreach ($events as $event) {
            $eventId = (int) $event->id;
            $metadata = self::arrayFromMixed($event->getAttribute('metadata'));

            foreach (self::alertUrlsForEvent($event, $metadata) as $url) {
                $normalized = self::normalizeUrl($url);

                if ($normalized === null) {
                    continue;
                }

                self::register($index, $normalized, $eventId);

                $reference = AzDoAlertReference::fromUrl($url);

                if ($reference === null) {
                    continue;
                }

                foreach (self::alertKeysForReference($reference, $metadata) as $key) {
                    self::register($alertKeyIndex, $key, $eventId);
                }
            }
        }

        return new self($index, $alertKeyIndex);
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

    /**
     * Match on canonical Azure DevOps alert identity, so an issue written with the GUID
     * form of an alert URL resolves to an event indexed under the name form and vice versa.
     *
     * @return list<int>
     */
    public function findByAlertIdentity(string $url): array
    {
        $reference = AzDoAlertReference::fromUrl($url);

        if ($reference === null) {
            return [];
        }

        return $this->alertKeyIndex[$reference->key()] ?? [];
    }

    /** @return list<int> */
    public function findAll(string $url): array
    {
        $combined = array_merge(
            $this->findExact($url),
            $this->findByAlertIdentity($url),
        );

        return array_values(array_unique($combined));
    }

    /**
     * URLs that identify this event's alert and nothing broader.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private static function alertUrlsForEvent(SecurityEvent $event, array $metadata): array
    {
        $urls = [];

        $eventUrl = (string) ($event->url ?? '');
        if (trim($eventUrl) !== '') {
            $urls[] = $eventUrl;
        }

        $alertWebUrl = SourceContextFacts::getString($metadata, SourceContextFacts::SOURCE_ALERT_WEB_URL);
        if ($alertWebUrl !== null) {
            $urls[] = $alertWebUrl;
        }

        $links = $metadata['links'] ?? null;
        if (is_array($links)) {
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $label = $link['label'] ?? null;
                $linkUrl = $link['url'] ?? null;

                if (! is_string($label) || strtolower(trim($label)) !== 'source alert') {
                    continue;
                }

                if (is_string($linkUrl) && trim($linkUrl) !== '') {
                    $urls[] = $linkUrl;
                }
            }
        }

        $sourceData = self::arrayFromMixed($event->getAttribute('source_data'));
        $alertUri = $sourceData['alertUri'] ?? null;

        if (is_string($alertUri) && trim($alertUri) !== '') {
            $urls[] = $alertUri;
        }

        return self::withoutSharedArticleUrl(array_values(array_unique($urls)), $metadata);
    }

    /**
     * ASoC exposes no per-issue web URL: its article URL is shared by every issue of that
     * type, and it is also the value stored as the event url. Drop it by value.
     *
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private static function withoutSharedArticleUrl(array $urls, array $metadata): array
    {
        $articleUrl = SourceContextFacts::getString($metadata, 'asoc.article.url');

        if ($articleUrl === null) {
            return $urls;
        }

        $normalizedArticleUrl = self::normalizeUrl($articleUrl);

        if ($normalizedArticleUrl === null) {
            return $urls;
        }

        return array_values(array_filter(
            $urls,
            static fn (string $url): bool => self::normalizeUrl($url) !== $normalizedArticleUrl,
        ));
    }

    /**
     * Every canonical key for one parsed alert reference: the cross-product of the project
     * and repository refs the event itself carries, so GUID and name forms collapse to one
     * identity without any lookup table or cross-event inference.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private static function alertKeysForReference(AzDoAlertReference $reference, array $metadata): array
    {
        $projectRefs = self::refCandidates([
            $reference->projectRef,
            SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_PROJECT_ID),
            SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_PROJECT_NAME),
        ]);

        $repositoryRefs = self::refCandidates([
            $reference->repositoryRef,
            SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_ID),
            SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_NAME),
        ]);

        $keys = [];

        foreach ($projectRefs as $projectRef) {
            foreach ($repositoryRefs as $repositoryRef) {
                $key = $reference->withRefs($projectRef, $repositoryRef)->key();

                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<string|null>  $refs
     * @return list<string>
     */
    private static function refCandidates(array $refs): array
    {
        $candidates = [];

        foreach ($refs as $ref) {
            if (! is_string($ref)) {
                continue;
            }

            $normalized = AzDoAlertReference::normalizeRef($ref);

            if ($normalized === '' || in_array($normalized, $candidates, true)) {
                continue;
            }

            $candidates[] = $normalized;
        }

        return $candidates;
    }

    /**
     * @param  array<string, list<int>>  $index
     */
    private static function register(array &$index, string $key, int $eventId): void
    {
        $index[$key] ??= [];

        if (! in_array($eventId, $index[$key], true)) {
            $index[$key][] = $eventId;
        }
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
