<?php

namespace App\SecurityEvents;

use App\Models\SecurityEvent;
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

        $add = function (string $label, ?string $url, string $kind) use (&$links, &$seen): void {
            if ($url === null || $url === '') {
                return;
            }

            if (! SourceLinkHelper::isSafeUrl($url)) {
                return;
            }

            if (isset($seen[$url])) {
                return;
            }

            $seen[$url] = true;
            $links[] = [
                'label' => $label,
                'url' => $url,
                'kind' => $kind,
                'external' => true,
            ];
        };

        // Source alert URL
        $add('Source alert', $event->url, self::KIND_SOURCE);

        // Source system URL
        $system = $event->getRelationValue('softwareSystem');
        if ($system !== null && is_string($system->url)) {
            $add('System', $system->url, self::KIND_SOURCE);
        }

        // Container URL
        $container = $event->getRelationValue('container');
        if ($container !== null && is_string($container->url)) {
            $add('Repository', $container->url, self::KIND_SOURCE);
        }

        // Version control / source file URL
        $add('Source file', $event->version_control_url, self::KIND_CODE);

        // Normalised metadata links array: [['label'=>..., 'url'=>...], ...]
        /** @var array<string, mixed>|null $metadata */
        $metadata = $event->getAttribute('metadata');

        if (is_array($metadata)) {
            $this->addMetadataLinks($metadata, $add);
        }

        // Work item links
        $workItemLinks = $event->getRelationValue('workItemLinks');
        if ($workItemLinks !== null) {
            foreach ($workItemLinks as $link) {
                /** @var WorkItemLink $link */
                if (is_string($link->work_item_url) && $link->work_item_url !== '') {
                    $label = trim(sprintf('%s: %s', $link->tracker_id, $link->work_item_title ?? $link->work_item_id));
                    $add($label, $link->work_item_url, self::KIND_TRACKER);
                }
            }
        }

        return $links;
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
