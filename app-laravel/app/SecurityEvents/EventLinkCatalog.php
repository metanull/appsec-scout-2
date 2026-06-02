<?php

namespace App\SecurityEvents;

use App\Models\CuratedLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\WorkItemLink;

/**
 * Builds a deduplicated, typed link catalog for a SecurityEvent from all
 * normalised columns, metadata, source data, related system/container URLs,
 * and linked work items.
 *
 * Each entry is an array{label: string, url: string, kind: string, external: bool}.
 */
final class EventLinkCatalog
{
    private const KIND_SOURCE = 'source';

    private const KIND_CODE = 'code';

    private const KIND_REMEDIATION = 'remediation';

    private const KIND_STANDARD = 'standard';

    private const KIND_TRACKER = 'tracker';

    /**
     * Returns the deduplicated link list for the given event.
     * The event should have softwareSystem, container, and workItemLinks loaded.
     *
     * @return list<array{label: string, url: string, kind: string, external: bool}>
     */
    public function build(SecurityEvent $event): array
    {
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
                'external' => true,
            ];
        };

        // Source alert URL
        $add('Source alert', $event->url, self::KIND_SOURCE, 3);

        // Source system URL
        $system = $event->relationLoaded('softwareSystem') ? $event->getRelation('softwareSystem') : null;
        if ($system instanceof SoftwareSystem && is_string($system->url)) {
            $add('System', $system->url, self::KIND_SOURCE, 1);

            $this->addCuratedLinks($system->relationLoaded('curatedLinks') ? $system->getRelation('curatedLinks') : null, $add, 1);
        }

        // Container URL
        $container = $event->relationLoaded('container') ? $event->getRelation('container') : null;
        if ($container instanceof SecurityContainer && is_string($container->url)) {
            $add('Repository', $container->url, self::KIND_SOURCE, 2);

            $this->addCuratedLinks($container->relationLoaded('curatedLinks') ? $container->getRelation('curatedLinks') : null, $add, 2);
        }

        // Version control / source file URL
        $add('Source file', $event->version_control_url, self::KIND_CODE, 3);

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
                    $add($label, $link->work_item_url, self::KIND_TRACKER, 0);
                }
            }
        }

        $this->addCuratedLinks($event->relationLoaded('curatedLinks') ? $event->getRelation('curatedLinks') : null, $add, 3);

        return $links;
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
            $add('CVE: ' . strtoupper($metadata['cve']), $cveUrl, self::KIND_STANDARD);
        }

        // CWE in metadata
        if (isset($metadata['cwe'])) {
            $cweUrl = SourceLinkHelper::cweLinkUrl($metadata['cwe']);
            $add('CWE: ' . $metadata['cwe'], $cweUrl, self::KIND_STANDARD);
        }

        // Rule help URI from AzDO tools
        if (isset($metadata['ruleHelpUri']) && is_string($metadata['ruleHelpUri'])) {
            $add('Rule documentation', $metadata['ruleHelpUri'], self::KIND_REMEDIATION);
        }

        // ASoC article URL
        if (isset($metadata['articleUrl']) && is_string($metadata['articleUrl'])) {
            $add('Issue article', $metadata['articleUrl'], self::KIND_REMEDIATION);
        }

        // Detectify details page (deduplicated with metadata.links iteration above)
        if (isset($metadata['detailsPage']) && is_string($metadata['detailsPage'])) {
            $add('Details page', $metadata['detailsPage'], self::KIND_SOURCE);
        }
    }

    private function inferKindFromLabel(string $label): string
    {
        $lower = strtolower($label);

        if (str_contains($lower, 'cve') || str_contains($lower, 'nvd') || str_contains($lower, 'cwe') || str_contains($lower, 'mitre')) {
            return self::KIND_STANDARD;
        }

        if (str_contains($lower, 'source') || str_contains($lower, 'article') || str_contains($lower, 'portal') || str_contains($lower, 'details')) {
            return self::KIND_SOURCE;
        }

        if (str_contains($lower, 'rule') || str_contains($lower, 'remediat') || str_contains($lower, 'doc')) {
            return self::KIND_REMEDIATION;
        }

        if (str_contains($lower, 'repo') || str_contains($lower, 'file') || str_contains($lower, 'code') || str_contains($lower, 'scan')) {
            return self::KIND_CODE;
        }

        if (str_contains($lower, 'jira') || str_contains($lower, 'issue') || str_contains($lower, 'ticket') || str_contains($lower, 'work item')) {
            return self::KIND_TRACKER;
        }

        return self::KIND_SOURCE;
    }

    /**
     * Return the display label for a link kind.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_SOURCE => 'Source',
            self::KIND_CODE => 'Code',
            self::KIND_REMEDIATION => 'Remediation',
            self::KIND_STANDARD => 'Standards',
            self::KIND_TRACKER => 'Tracker',
            default => 'Other',
        };
    }
}
