<?php

namespace App\SecurityEvents;

use App\Models\CuratedLink;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Trackers\Registry as TrackerRegistry;

/**
 * Builds deduplicated navigation link catalogs for software systems and
 * security containers.
 *
 * Each entry is an array{label: string, url: string, kind: string, kind_label: string, external: bool}.
 */
final class EntityNavigationCatalog
{
    /** @var array<string, ?string> */
    private array $trackerProjectUrlCache = [];

    public function __construct(private readonly TrackerRegistry $trackerRegistry) {}

    /**
     * @return list<array{label: string, url: string, kind: string, kind_label: string, external: bool}>
     */
    public function buildForSoftwareSystem(SoftwareSystem $system): array
    {
        return $this->build(
            entityLabel: 'System',
            entityUrl: is_string($system->url) ? $system->url : null,
            metadata: $system->getAttribute('metadata'),
            curatedLinks: $system->relationLoaded('curatedLinks') ? $system->getRelation('curatedLinks') : null,
            trackerProjectLinks: $system->relationLoaded('trackerProjectLinks') ? $system->getRelation('trackerProjectLinks') : null,
            repositoryMappings: $system->relationLoaded('repositoryMappings') ? $system->getRelation('repositoryMappings') : null,
        );
    }

    /**
     * @return list<array{label: string, url: string, kind: string, kind_label: string, external: bool}>
     */
    public function buildForSecurityContainer(SecurityContainer $container): array
    {
        return $this->build(
            entityLabel: 'Container',
            entityUrl: is_string($container->url) ? $container->url : null,
            metadata: $container->getAttribute('metadata'),
            curatedLinks: $container->relationLoaded('curatedLinks') ? $container->getRelation('curatedLinks') : null,
            trackerProjectLinks: $container->relationLoaded('trackerProjectLinks') ? $container->getRelation('trackerProjectLinks') : null,
            repositoryMappings: $container->relationLoaded('repositoryMappings') ? $container->getRelation('repositoryMappings') : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  iterable<CuratedLink>|null  $curatedLinks
     * @param  iterable<TrackerProjectLink>|null  $trackerProjectLinks
     * @param  iterable<RepositoryMapping>|null  $repositoryMappings
     * @return list<array{label: string, url: string, kind: string, kind_label: string, external: bool}>
     */
    private function build(
        string $entityLabel,
        ?string $entityUrl,
        mixed $metadata,
        ?iterable $curatedLinks,
        ?iterable $trackerProjectLinks,
        ?iterable $repositoryMappings,
    ): array {
        $links = [];
        $seen = [];

        $add = function (string $label, ?string $url, string $kind, int $priority = 0) use (&$links, &$seen): void {
            if ($url === null || $url === '') {
                return;
            }

            if (! SourceLinkHelper::isSafeUrl($url)) {
                return;
            }

            if (isset($seen[$url])) {
                if ($priority <= $seen[$url]['priority']) {
                    return;
                }

                $links[$seen[$url]['index']] = [
                    'label' => $label,
                    'url' => $url,
                    'kind' => $kind,
                    'kind_label' => EventLinkCatalog::kindLabel($kind),
                    'external' => true,
                ];
                $seen[$url]['priority'] = $priority;

                return;
            }

            $seen[$url] = [
                'priority' => $priority,
                'index' => count($links),
            ];
            $links[] = [
                'label' => $label,
                'url' => $url,
                'kind' => $kind,
                'kind_label' => EventLinkCatalog::kindLabel($kind),
                'external' => true,
            ];
        };

        $add($entityLabel, $entityUrl, 'source', 2);

        if (is_array($metadata)) {
            $this->addMetadataLinks($metadata, $add);
        }

        $this->addCuratedLinks($curatedLinks, $add, 3);
        $this->addTrackerProjectLinks($trackerProjectLinks, $add, 1);
        $this->addRepositoryMappings($repositoryMappings, $add, 1);

        return $links;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addMetadataLinks(array $metadata, callable $add): void
    {
        if (isset($metadata['links']) && is_array($metadata['links'])) {
            foreach ($metadata['links'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = is_string($entry['label'] ?? null) ? (string) $entry['label'] : 'Link';
                $url = is_string($entry['url'] ?? null) ? (string) $entry['url'] : null;
                $kind = $this->inferKindFromLabel($label);

                $add($label, $url, $kind, 2);
            }
        }

        if (isset($metadata['cve']) && is_string($metadata['cve'])) {
            $cveUrl = SourceLinkHelper::cveLinkUrl($metadata['cve']);
            $add('CVE: ' . strtoupper($metadata['cve']), $cveUrl, 'standard', 2);
        }

        if (isset($metadata['cwe'])) {
            $cweUrl = SourceLinkHelper::cweLinkUrl($metadata['cwe']);
            $add('CWE: ' . $metadata['cwe'], $cweUrl, 'standard', 2);
        }
    }

    /**
     * @param  iterable<CuratedLink>|null  $links
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addCuratedLinks(?iterable $links, callable $add, int $priority): void
    {
        if ($links === null) {
            return;
        }

        foreach ($links as $link) {
            $add($link->label, $link->url, $link->kind, $priority);
        }
    }

    /**
     * @param  iterable<TrackerProjectLink>|null  $links
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addTrackerProjectLinks(?iterable $links, callable $add, int $priority): void
    {
        if ($links === null) {
            return;
        }

        foreach ($links as $link) {
            $label = trim(sprintf('%s: %s', $link->tracker_id, $link->project_name ?? $link->project_key));
            $url = $this->resolveTrackerProjectUrl($link->tracker_id, $link->project_key);

            $add($label, $url, 'tracker', $priority);
        }
    }

    /**
     * @param  iterable<RepositoryMapping>|null  $links
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addRepositoryMappings(?iterable $links, callable $add, int $priority): void
    {
        if ($links === null) {
            return;
        }

        foreach ($links as $link) {
            if ($link->repository_url === '') {
                continue;
            }

            $add('Repository: ' . $link->repository_name, $link->repository_url, 'code', $priority);
        }
    }

    private function inferKindFromLabel(string $label): string
    {
        $lower = strtolower($label);

        if (str_contains($lower, 'cve') || str_contains($lower, 'nvd') || str_contains($lower, 'cwe') || str_contains($lower, 'mitre')) {
            return 'standard';
        }

        if (str_contains($lower, 'source') || str_contains($lower, 'article') || str_contains($lower, 'portal') || str_contains($lower, 'details')) {
            return 'source';
        }

        if (str_contains($lower, 'rule') || str_contains($lower, 'remediat') || str_contains($lower, 'doc')) {
            return 'remediation';
        }

        if (str_contains($lower, 'repo') || str_contains($lower, 'file') || str_contains($lower, 'code') || str_contains($lower, 'scan')) {
            return 'code';
        }

        if (str_contains($lower, 'jira') || str_contains($lower, 'issue') || str_contains($lower, 'ticket') || str_contains($lower, 'work item')) {
            return 'tracker';
        }

        return 'source';
    }

    private function resolveTrackerProjectUrl(string $trackerId, string $projectKey): ?string
    {
        $cacheKey = $trackerId . '|' . $projectKey;

        if (array_key_exists($cacheKey, $this->trackerProjectUrlCache)) {
            return $this->trackerProjectUrlCache[$cacheKey];
        }

        $tracker = $this->trackerRegistry->find($trackerId);

        if ($tracker === null) {
            return $this->trackerProjectUrlCache[$cacheKey] = null;
        }

        try {
            foreach ($tracker->fetchProjects() as $project) {
                if ($project->key === $projectKey && $project->url !== null && SourceLinkHelper::isSafeUrl($project->url)) {
                    return $this->trackerProjectUrlCache[$cacheKey] = $project->url;
                }
            }
        } catch (\Throwable) {
            return $this->trackerProjectUrlCache[$cacheKey] = null;
        }

        return $this->trackerProjectUrlCache[$cacheKey] = null;
    }
}
