<?php

namespace App\Trackers;

use App\Models\Enums\EventSeverity;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\SecurityEvents\SourceLinkHelper;
use Illuminate\Support\Carbon;

final class DescriptionBuilder
{
    private const DEFAULT_MAX_BYTES = 16384;

    private const TRUNCATION_MARKER = '...(truncated)';

    public function buildSingle(SecurityEvent $event): string
    {
        return $this->truncateAtParagraphBoundary(implode("\n\n", array_filter([
            sprintf('## %s', $this->buildTitle($event)),
            $this->buildEventSummary($event),
            $this->buildAlertLinks($event),
            $this->buildSourceLinks($event),
            $this->buildEventDescription($event),
            $this->buildRemediation($event),
            $this->buildRemediationLinks($event),
            $this->buildOccurrences([$event]),
        ])));
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     */
    public function buildGrouped(iterable $events): string
    {
        $events = $this->materializeEvents($events);

        if (count($events) === 1) {
            return $this->buildSingle($events[0]);
        }

        $sections = [
            sprintf('## %s', $this->buildGroupedTitle($events)),
            $this->buildSeverityTable($events),
        ];

        foreach ($this->groupEventsByType($events) as $group) {
            $sections[] = sprintf('## %s', $this->typeLabel($group['type']));
            $sections[] = $this->buildSharedDescription($group['events']);
            $sections[] = $this->buildRemediation($this->firstWithValue($group['events'], 'remediation') ?? $group['events'][0]);
            $sections[] = $this->buildGroupRemediationLinks($group['events']);
            $sections[] = $this->buildOccurrences($group['events']);
        }

        return $this->truncateAtParagraphBoundary(implode("\n\n", array_filter($sections)));
    }

    public function buildTitle(SecurityEvent $event): string
    {
        return $this->buildGroupedTitle([$event]);
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     */
    public function buildGroupedTitle(iterable $events): string
    {
        $events = $this->materializeEvents($events);
        $first = $events[0];
        $context = $this->systemName($first);
        $container = $this->containerName($first);
        $types = array_values(array_unique(array_map(
            fn (SecurityEvent $event): string => $this->typeLabel($this->typeValue($event) ?? 'finding'),
            $events,
        )));
        sort($types);

        $typeSummary = count($types) <= 2
            ? implode(', ', $types)
            : sprintf('%s + %d more', $types[0], count($types) - 1);

        $parts = [$context];

        if ($container !== null && $container !== $context) {
            $parts[] = $container;
        }

        $parts[] = sprintf(
            '%s (%d alert%s, %d file%s)',
            $typeSummary,
            count($events),
            count($events) === 1 ? '' : 's',
            $this->countUniqueFiles($events),
            $this->countUniqueFiles($events) === 1 ? '' : 's',
        );

        return implode(': ', $parts);
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     * @return list<SecurityEvent>
     */
    private function materializeEvents(iterable $events): array
    {
        return array_values(is_array($events) ? $events : iterator_to_array($events, false));
    }

    private function buildEventSummary(SecurityEvent $event): string
    {
        $lines = [
            sprintf('- Severity: %s', $this->severityLabel($this->severityValue($event))),
            sprintf('- Type: %s', $this->typeLabel($this->typeValue($event) ?? 'finding')),
            sprintf('- State: %s', $this->stateLabel($this->stateValue($event) ?? 'open')),
        ];

        if (is_string($event->rule_id) && $event->rule_id !== '') {
            $lines[] = sprintf('- Rule: %s', $event->rule_id);
        }

        if ($event->source_event_id !== '') {
            $lines[] = sprintf('- Source Event ID: %s', $event->source_event_id);
        }

        if ($event->first_seen_at !== null) {
            $lines[] = sprintf('- First Seen: %s', Carbon::parse((string) $event->first_seen_at)->toDateTimeString());
        }

        if ($event->last_seen_at !== null) {
            $lines[] = sprintf('- Last Seen: %s', Carbon::parse((string) $event->last_seen_at)->toDateTimeString());
        }

        return implode("\n", $lines);
    }

    /**
     * Renders the alert's own URL as a single-line "View alert" link section.
     */
    private function buildAlertLinks(SecurityEvent $event): ?string
    {
        if (! is_string($event->url) || $event->url === '') {
            return null;
        }

        if (! SourceLinkHelper::isSafeUrl($event->url)) {
            return null;
        }

        return sprintf("### Alert\n\n- [View alert](%s)", $event->url);
    }

    /**
     * Renders source/code/standard links extracted from event metadata.
     * Returns null when no links are available.
     */
    private function buildSourceLinks(SecurityEvent $event): ?string
    {
        $items = $this->extractSourceLinks($event);

        if ($items === []) {
            return null;
        }

        $lines = ['### References'];

        foreach ($items as [$label, $url]) {
            $lines[] = sprintf('- [%s](%s)', $label, $url);
        }

        return implode("\n", $lines);
    }

    /**
     * Renders shared remediation links for a type group (first event that has them wins).
     *
     * @param  list<SecurityEvent>  $events
     */
    private function buildGroupRemediationLinks(array $events): ?string
    {
        foreach ($events as $event) {
            $result = $this->buildRemediationLinks($event);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Renders rule/remediation reference links from event metadata.
     */
    private function buildRemediationLinks(SecurityEvent $event): ?string
    {
        $items = $this->extractRemediationLinks($event);

        if ($items === []) {
            return null;
        }

        $lines = ['### Remediation References'];

        foreach ($items as [$label, $url]) {
            $lines[] = sprintf('- [%s](%s)', $label, $url);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function extractSourceLinks(SecurityEvent $event): array
    {
        $items = [];
        /** @var array<string, mixed>|null $metadata */
        $metadata = $event->getAttribute('metadata');

        if (is_array($metadata)) {
            // CVE
            if (isset($metadata['cve']) && is_string($metadata['cve'])) {
                $url = SourceLinkHelper::cveLinkUrl($metadata['cve']);
                if ($url !== null) {
                    $items[] = ['CVE: ' . strtoupper($metadata['cve']), $url];
                }
            }

            // CWE
            if (isset($metadata['cwe'])) {
                $url = SourceLinkHelper::cweLinkUrl($metadata['cwe']);
                if ($url !== null) {
                    $items[] = ['CWE: ' . $metadata['cwe'], $url];
                }
            }

            // Generic metadata.links (source/code kinds produced by normalizers)
            if (isset($metadata['links']) && is_array($metadata['links'])) {
                foreach ($metadata['links'] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $label = is_string($entry['label'] ?? null) ? (string) $entry['label'] : 'Link';
                    $url = is_string($entry['url'] ?? null) ? (string) $entry['url'] : null;

                    if ($url === null || ! SourceLinkHelper::isSafeUrl($url)) {
                        continue;
                    }

                    // Skip remediation-kind links; they go into buildRemediationLinks
                    $lower = strtolower($label);
                    if (str_contains($lower, 'rule') || str_contains($lower, 'remediat') || str_contains($lower, 'doc')) {
                        continue;
                    }

                    $items[] = [$label, $url];
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function extractRemediationLinks(SecurityEvent $event): array
    {
        $items = [];
        /** @var array<string, mixed>|null $metadata */
        $metadata = $event->getAttribute('metadata');

        if (is_array($metadata)) {
            if (isset($metadata['ruleHelpUri']) && is_string($metadata['ruleHelpUri']) && SourceLinkHelper::isSafeUrl($metadata['ruleHelpUri'])) {
                $items[] = ['Rule documentation', $metadata['ruleHelpUri']];
            }

            if (isset($metadata['articleUrl']) && is_string($metadata['articleUrl']) && SourceLinkHelper::isSafeUrl($metadata['articleUrl'])) {
                $items[] = ['Issue article', $metadata['articleUrl']];
            }

            // Rule-kind entries from metadata.links
            if (isset($metadata['links']) && is_array($metadata['links'])) {
                foreach ($metadata['links'] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $label = is_string($entry['label'] ?? null) ? (string) $entry['label'] : 'Link';
                    $url = is_string($entry['url'] ?? null) ? (string) $entry['url'] : null;

                    if ($url === null || ! SourceLinkHelper::isSafeUrl($url)) {
                        continue;
                    }

                    $lower = strtolower($label);

                    if (str_contains($lower, 'rule') || str_contains($lower, 'remediat') || str_contains($lower, 'doc')) {
                        $items[] = [$label, $url];
                    }
                }
            }
        }

        return $items;
    }

    private function buildEventDescription(SecurityEvent $event): ?string
    {
        if ($event->description === null || trim($event->description) === '') {
            return null;
        }

        return "### Description\n\n{$event->description}";
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function buildSharedDescription(array $events): ?string
    {
        $event = $this->firstWithValue($events, 'description');

        if ($event === null || $event->description === null || trim($event->description) === '') {
            return null;
        }

        return "### Description\n\n{$event->description}";
    }

    private function buildRemediation(SecurityEvent $event): ?string
    {
        if ($event->remediation === null || trim($event->remediation) === '') {
            return null;
        }

        return "### Remediation\n\n{$event->remediation}";
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function buildOccurrences(array $events): string
    {
        $lines = ['### Occurrences'];

        foreach ($events as $event) {
            $lines[] = $this->buildOccurrenceLine($event);
        }

        return implode("\n", $lines);
    }

    private function buildOccurrenceLine(SecurityEvent $event): string
    {
        $context = $this->systemName($event);
        $container = $this->containerName($event);
        $location = $event->file_path ?? $event->title;

        if ($event->start_line !== null) {
            $location .= ':' . $event->start_line;
        }

        $parts = [$context];

        if ($container !== null && $container !== $context) {
            $parts[] = $container;
        }

        $parts[] = $location;

        $line = '- ' . implode('/', $parts);

        // Alert URL (primary link)
        if ($event->url !== null && SourceLinkHelper::isSafeUrl($event->url)) {
            $line .= sprintf(' ([alert](%s))', $event->url);
        }

        // Source-code / version-control link
        if (is_string($event->version_control_url) && $event->version_control_url !== '' && SourceLinkHelper::isSafeUrl($event->version_control_url)) {
            $line .= sprintf(' ([source](%s))', $event->version_control_url);
        }

        return $line;
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function buildSeverityTable(array $events): string
    {
        $counts = [
            EventSeverity::Critical->value => 0,
            EventSeverity::High->value => 0,
            EventSeverity::Medium->value => 0,
            EventSeverity::Low->value => 0,
            EventSeverity::Informational->value => 0,
        ];

        foreach ($events as $event) {
            $severity = $this->severityValue($event);

            if ($severity !== null && array_key_exists($severity, $counts)) {
                $counts[$severity]++;
            }
        }

        $lines = ['| Severity | Count |', '| --- | ---: |'];

        foreach ($counts as $severity => $count) {
            if ($count > 0) {
                $lines[] = sprintf('| %s | %d |', $this->severityLabel($severity), $count);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<SecurityEvent>  $events
     * @return list<array{type: string, events: list<SecurityEvent>}>
     */
    private function groupEventsByType(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $type = $this->typeValue($event) ?? 'finding';
            $groups[$type][] = $event;
        }

        uasort($groups, fn (array $left, array $right): int => $this->highestSeverityWeight($right) <=> $this->highestSeverityWeight($left));

        $resolved = [];

        foreach ($groups as $type => $groupEvents) {
            $resolved[] = ['type' => $type, 'events' => $groupEvents];
        }

        return $resolved;
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function highestSeverityWeight(array $events): int
    {
        if ($events === []) {
            return 1;
        }

        return max(array_map(fn (SecurityEvent $event): int => $this->severityWeight($this->severityValue($event)), $events));
    }

    private function systemName(SecurityEvent $event): string
    {
        $system = $event->getRelationValue('softwareSystem');

        return $system instanceof SoftwareSystem ? $system->name : 'Security';
    }

    private function containerName(SecurityEvent $event): ?string
    {
        $container = $event->getRelationValue('container');

        return $container instanceof SecurityContainer ? $container->name : null;
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function countUniqueFiles(array $events): int
    {
        $paths = array_unique(array_filter(array_map(
            fn (SecurityEvent $event): ?string => is_string($event->file_path) && $event->file_path !== '' ? $event->file_path : null,
            $events,
        )));

        return max(count($paths), 1);
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function firstWithValue(array $events, string $attribute): ?SecurityEvent
    {
        foreach ($events as $event) {
            $value = $event->{$attribute};

            if (is_string($value) && trim($value) !== '') {
                return $event;
            }
        }

        return null;
    }

    private function truncateAtParagraphBoundary(string $markdown, int $maxBytes = self::DEFAULT_MAX_BYTES): string
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

    private function severityLabel(?string $severity): string
    {
        return $this->label($severity ?? 'informational');
    }

    private function severityValue(SecurityEvent $event): ?string
    {
        return $this->stringValue($event->severity);
    }

    private function typeValue(SecurityEvent $event): ?string
    {
        return $this->stringValue($event->type);
    }

    private function stateValue(SecurityEvent $event): ?string
    {
        return $this->stringValue($event->state);
    }

    private function typeLabel(string $type): string
    {
        return $this->label($type);
    }

    private function stateLabel(string $state): string
    {
        return $this->label($state);
    }

    private function label(string $value): string
    {
        return str_replace('_', ' ', ucfirst($value));
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function severityWeight(?string $severity): int
    {
        return match ($severity) {
            EventSeverity::Critical->value => 5,
            EventSeverity::High->value => 4,
            EventSeverity::Medium->value => 3,
            EventSeverity::Low->value => 2,
            default => 1,
        };
    }
}
