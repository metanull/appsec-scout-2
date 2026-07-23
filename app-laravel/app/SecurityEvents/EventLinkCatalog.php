<?php

namespace App\SecurityEvents;

use App\Models\CuratedLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\WorkItemLink;
use App\SourceCode\CodeLocation;
use App\SourceCode\RepositoryCodeIdentity;
use App\SourceCode\RepositoryCodeIdentityResolver;
use App\SourceCode\RepositoryCodeUrlGenerator;

/**
 * Builds a deduplicated, typed link catalog for a SecurityEvent from all
 * normalised columns, metadata, source data, related system/container URLs,
 * and linked work items.
 *
 * Each entry is an array{label: string, url: string, kind: string, external: bool}.
 */
final class EventLinkCatalog
{
    public function __construct(
        private readonly RepositoryCodeIdentityResolver $repositoryCodeIdentityResolver,
        private readonly RepositoryCodeUrlGenerator $repositoryCodeUrlGenerator,
    ) {}

    /**
     * Returns the deduplicated link list for the given event.
     * The event should have softwareSystem, container, and workItemLinks loaded.
     *
     * @return list<array{label: string, url: string, kind: string, external: bool}>
     */
    public function build(SecurityEvent $event): array
    {
        $collector = new LinkCollector;

        $add = static function (string $label, ?string $url, string $kind, int $priority = 0) use ($collector): void {
            $collector->add($label, $url, $kind, $priority);
        };

        // Source alert URL
        $add('Source alert', $event->url, LinkCollector::KIND_SOURCE, 3);

        // Source system URL
        $system = $event->relationLoaded('softwareSystem') ? $event->getRelation('softwareSystem') : null;
        if ($system instanceof SoftwareSystem && is_string($system->url)) {
            $add('System', $system->url, LinkCollector::KIND_SOURCE, 1);

            $this->addCuratedLinks($system->relationLoaded('curatedLinks') ? $system->getRelation('curatedLinks') : null, $add, 1);
        }

        // Container URL
        $container = $event->relationLoaded('container') ? $event->getRelation('container') : null;
        if ($container instanceof SecurityContainer && is_string($container->url)) {
            $add('Repository', $container->url, LinkCollector::KIND_SOURCE, 2);

            $this->addCuratedLinks($container->relationLoaded('curatedLinks') ? $container->getRelation('curatedLinks') : null, $add, 2);
        }

        // Version control / source file URL
        $add('Source file', $event->version_control_url, LinkCollector::KIND_CODE, 3);

        $this->addRepositoryLinks($event, $add);

        // Normalised metadata links array: [['label'=>..., 'url'=>...], ...]
        /** @var array<string, mixed>|null $metadata */
        $metadata = $event->getAttribute('metadata');

        if (is_array($metadata)) {
            $this->addMetadataLinks($metadata, $add);
        }

        // Work item links
        $workItemLinks = $event->relationLoaded('workItemLinks') ? $event->getRelation('workItemLinks') : null;
        if ($workItemLinks !== null) {
            foreach ($workItemLinks as $link) {
                /** @var WorkItemLink $link */
                if (is_string($link->work_item_url) && $link->work_item_url !== '') {
                    $label = trim(sprintf('%s: %s', $link->tracker_id, $link->work_item_title ?? $link->work_item_id));
                    $add($label, $link->work_item_url, LinkCollector::KIND_TRACKER, 0);
                }
            }
        }

        $this->addCuratedLinks($event->relationLoaded('curatedLinks') ? $event->getRelation('curatedLinks') : null, $add, 3);

        return $collector->all();
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
     * @param  array<string, mixed>  $metadata
     * @param  callable(string, ?string, string): void  $add
     */
    private function addMetadataLinks(array $metadata, callable $add): void
    {
        // Standard metadata.links array produced by normalizers
        if (isset($metadata['links']) && is_array($metadata['links'])) {
            foreach ($metadata['links'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = is_string($entry['label'] ?? null) ? (string) $entry['label'] : 'Link';
                $url = is_string($entry['url'] ?? null) ? (string) $entry['url'] : null;
                $kind = $this->inferKindFromLabel($label);

                $add($label, $url, $kind);
            }
        }

        // CVE in metadata (e.g. from AzDO dependency or ASoC)
        if (isset($metadata['cve']) && is_string($metadata['cve'])) {
            $cveUrl = SourceLinkHelper::cveLinkUrl($metadata['cve']);
            $add('CVE: ' . strtoupper($metadata['cve']), $cveUrl, LinkCollector::KIND_STANDARD);
        }

        // CWE in metadata
        if (isset($metadata['cwe'])) {
            $cweUrl = SourceLinkHelper::cweLinkUrl($metadata['cwe']);
            $add('CWE: ' . $metadata['cwe'], $cweUrl, LinkCollector::KIND_STANDARD);
        }

        // Rule help URI from AzDO tools
        if (isset($metadata['ruleHelpUri']) && is_string($metadata['ruleHelpUri'])) {
            $add('Rule documentation', $metadata['ruleHelpUri'], LinkCollector::KIND_REMEDIATION);
        }

        // ASoC article URL
        if (isset($metadata['articleUrl']) && is_string($metadata['articleUrl'])) {
            $add('Issue article', $metadata['articleUrl'], LinkCollector::KIND_REMEDIATION);
        }

        // Detectify details page (deduplicated with metadata.links iteration above)
        if (isset($metadata['detailsPage']) && is_string($metadata['detailsPage'])) {
            $add('Details page', $metadata['detailsPage'], LinkCollector::KIND_SOURCE);
        }
    }

    /**
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addRepositoryLinks(SecurityEvent $event, callable $add): void
    {
        $event->loadMissing([
            'container.repositoryMappings.repositoryProvider',
            'softwareSystem.repositoryMappings.repositoryProvider',
        ]);

        $identity = $this->repositoryCodeIdentityResolver->resolve($event->container, $event->softwareSystem);

        if (! $identity instanceof RepositoryCodeIdentity) {
            return;
        }

        $add('Repository', $this->repositoryCodeUrlGenerator->repositoryUrlFor($identity), LinkCollector::KIND_CODE, 3);

        $filePath = $event->file_path;

        if (! is_string($filePath) || $filePath === '') {
            return;
        }

        $location = new CodeLocation(
            filePath: $filePath,
            startLine: is_int($event->start_line) ? $event->start_line : null,
            endLine: is_int($event->end_line) ? $event->end_line : null,
            branch: is_string($event->branch) ? $event->branch : null,
            commitSha: is_string($event->commit_sha) ? $event->commit_sha : null,
        );

        $fileUrl = $this->repositoryCodeUrlGenerator->fileUrlFor($identity, $location);
        $add('Source file', $fileUrl, LinkCollector::KIND_CODE, 3);
    }

    private function inferKindFromLabel(string $label): string
    {
        $lower = strtolower($label);

        if (str_contains($lower, 'cve') || str_contains($lower, 'nvd') || str_contains($lower, 'cwe') || str_contains($lower, 'mitre')) {
            return LinkCollector::KIND_STANDARD;
        }

        if (str_contains($lower, 'source') || str_contains($lower, 'article') || str_contains($lower, 'portal') || str_contains($lower, 'details')) {
            return LinkCollector::KIND_SOURCE;
        }

        if (str_contains($lower, 'rule') || str_contains($lower, 'remediat') || str_contains($lower, 'doc')) {
            return LinkCollector::KIND_REMEDIATION;
        }

        if (str_contains($lower, 'repo') || str_contains($lower, 'file') || str_contains($lower, 'code') || str_contains($lower, 'scan')) {
            return LinkCollector::KIND_CODE;
        }

        if (str_contains($lower, 'jira') || str_contains($lower, 'issue') || str_contains($lower, 'ticket') || str_contains($lower, 'work item')) {
            return LinkCollector::KIND_TRACKER;
        }

        return LinkCollector::KIND_SOURCE;
    }

    /**
     * Return the display label for a link kind.
     */
    public static function kindLabel(string $kind): string
    {
        return LinkCollector::kindLabel($kind);
    }
}
