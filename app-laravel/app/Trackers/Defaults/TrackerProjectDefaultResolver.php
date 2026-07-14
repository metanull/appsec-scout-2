<?php

namespace App\Trackers\Defaults;

use App\Models\SecurityEvent;
use App\Models\TrackerProjectLink;
use App\Trackers\TrackerConfigRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class TrackerProjectDefaultResolver
{
    public function __construct(
        private readonly TrackerConfigRepository $trackerConfigRepository,
    ) {}

    public function resolveForEvent(
        SecurityEvent $event,
        string $trackerId,
    ): TrackerProjectDefaultResolution {
        $normalizedTrackerId = trim($trackerId);

        if ($normalizedTrackerId === '') {
            return TrackerProjectDefaultResolution::none($trackerId, 'Tracker is required to resolve a default project.');
        }

        $warnings = [];

        $container = $this->containerResolution($event, $normalizedTrackerId);

        if ($container['warning'] !== null) {
            $warnings[] = $container['warning'];
        }

        if ($container['resolution'] instanceof TrackerProjectDefaultResolution) {
            return $this->withWarnings($container['resolution'], $warnings);
        }

        $system = $this->systemResolution($event, $normalizedTrackerId);

        if ($system['warning'] !== null) {
            $warnings[] = $system['warning'];
        }

        if ($system['resolution'] instanceof TrackerProjectDefaultResolution) {
            return $this->withWarnings($system['resolution'], $warnings);
        }

        $fallback = $this->trackerFallback($normalizedTrackerId);

        if ($fallback instanceof TrackerProjectDefaultResolution) {
            return $this->withWarnings($fallback, $warnings);
        }

        return $this->withWarnings(
            TrackerProjectDefaultResolution::none(
                trackerId: $normalizedTrackerId,
                reasonText: 'No accepted context mapping or tracker fallback configured for this tracker.',
            ),
            $warnings,
        );
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     */
    public function resolveForEvents(
        iterable $events,
        string $trackerId,
    ): TrackerProjectDefaultResolution {
        $normalizedTrackerId = trim($trackerId);

        if ($normalizedTrackerId === '') {
            return TrackerProjectDefaultResolution::none($trackerId, 'Tracker is required to resolve a default project.');
        }

        $resolved = [];

        foreach ($events as $event) {
            $resolved[] = $this->resolveForEvent($event, $normalizedTrackerId);
        }

        $warnings = $this->collectWarnings($resolved);

        if ($resolved === []) {
            return $this->withWarnings(
                TrackerProjectDefaultResolution::none($normalizedTrackerId, 'No alerts were provided for default resolution.'),
                $warnings,
            );
        }

        if (count($resolved) === 1) {
            return $resolved[0];
        }

        $defaults = array_values(array_filter($resolved, fn (TrackerProjectDefaultResolution $row): bool => $row->hasDefault()));

        if ($defaults === []) {
            return $this->withWarnings(
                TrackerProjectDefaultResolution::none(
                    trackerId: $normalizedTrackerId,
                    reasonText: 'No selected alerts resolved to a tracker project default.',
                ),
                $warnings,
            );
        }

        if (count($defaults) !== count($resolved)) {
            return $this->withWarnings(
                TrackerProjectDefaultResolution::none(
                    trackerId: $normalizedTrackerId,
                    reasonText: 'Some selected alerts have no project default, so no grouped default was applied.',
                ),
                $warnings,
            );
        }

        $first = $defaults[0];

        foreach ($defaults as $candidate) {
            if ($candidate->projectKey !== $first->projectKey) {
                return $this->withWarnings(
                    TrackerProjectDefaultResolution::none(
                        trackerId: $normalizedTrackerId,
                        reasonText: 'Selected alerts resolve to different projects, so no grouped default was applied.',
                    ),
                    $warnings,
                );
            }
        }

        return $this->withWarnings(
            TrackerProjectDefaultResolution::resolved(
                trackerId: $first->trackerId,
                projectKey: (string) $first->projectKey,
                projectName: $first->projectName,
                source: $first->source ?? 'context_mapping',
                confidenceLabel: $first->confidenceLabel,
                reasonText: 'All selected alerts resolved to the same project default.',
            ),
            $warnings,
        );
    }

    /** @return array{resolution: ?TrackerProjectDefaultResolution, warning: ?string} */
    private function containerResolution(SecurityEvent $event, string $trackerId): array
    {
        $event->loadMissing('container.trackerProjectLinks');

        $container = $event->getRelationValue('container');

        if ($container === null) {
            return ['resolution' => null, 'warning' => null];
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $links */
        $links = $container->trackerProjectLinks->where('tracker_id', $trackerId)->values();

        return $this->resolveFromLinks(
            links: $links,
            trackerId: $trackerId,
            source: 'container_mapping',
            confidenceLabel: 'Container mapping',
            reasonText: 'Resolved from accepted container tracker mapping.',
            levelLabel: 'container',
        );
    }

    /** @return array{resolution: ?TrackerProjectDefaultResolution, warning: ?string} */
    private function systemResolution(SecurityEvent $event, string $trackerId): array
    {
        $event->loadMissing('softwareSystem.trackerProjectLinks');

        $system = $event->getRelationValue('softwareSystem');

        if ($system === null) {
            return ['resolution' => null, 'warning' => null];
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $links */
        $links = $system->trackerProjectLinks->where('tracker_id', $trackerId)->values();

        return $this->resolveFromLinks(
            links: $links,
            trackerId: $trackerId,
            source: 'system_mapping',
            confidenceLabel: 'System mapping',
            reasonText: 'Resolved from accepted system tracker mapping.',
            levelLabel: 'system',
        );
    }

    private function trackerFallback(string $trackerId): ?TrackerProjectDefaultResolution
    {
        if ($trackerId !== 'jira') {
            return null;
        }

        $jiraDefault = $this->trackerConfigRepository->getJiraDefaultProjectKey();

        if ($jiraDefault === null) {
            return null;
        }

        return TrackerProjectDefaultResolution::resolved(
            trackerId: 'jira',
            projectKey: $jiraDefault,
            projectName: null,
            source: 'tracker_fallback',
            confidenceLabel: 'Tracker fallback',
            reasonText: 'Resolved from configured Jira default project key.',
        );
    }

    /**
     * @param  EloquentCollection<int, TrackerProjectLink>  $links
     * @return array{resolution: ?TrackerProjectDefaultResolution, warning: ?string}
     */
    private function resolveFromLinks(
        EloquentCollection $links,
        string $trackerId,
        string $source,
        string $confidenceLabel,
        string $reasonText,
        string $levelLabel,
    ): array {
        if ($links->isEmpty()) {
            return ['resolution' => null, 'warning' => null];
        }

        if ($links->count() === 1) {
            $selected = $links->first();

            return [
                'resolution' => TrackerProjectDefaultResolution::resolved(
                    trackerId: $trackerId,
                    projectKey: $selected->project_key,
                    projectName: $selected->project_name,
                    source: $source,
                    confidenceLabel: $confidenceLabel,
                    reasonText: $reasonText,
                ),
                'warning' => null,
            ];
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $defaults */
        $defaults = $links->filter(fn (TrackerProjectLink $link): bool => (bool) $link->is_default)->values();

        if ($defaults->count() !== 1) {
            $ambiguity = $defaults->count() === 0
                ? 'none of them is marked as the default'
                : 'more than one of them is marked as the default';

            return [
                'resolution' => null,
                'warning' => sprintf(
                    'Multiple %s tracker project links are configured at the %s level, but %s — mark exactly one as default to resolve a project automatically.',
                    $trackerId,
                    $levelLabel,
                    $ambiguity,
                ),
            ];
        }

        $selected = $defaults->first();

        if ($selected === null) {
            return ['resolution' => null, 'warning' => null];
        }

        return [
            'resolution' => TrackerProjectDefaultResolution::resolved(
                trackerId: $trackerId,
                projectKey: $selected->project_key,
                projectName: $selected->project_name,
                source: $source,
                confidenceLabel: $confidenceLabel,
                reasonText: $reasonText,
            ),
            'warning' => null,
        ];
    }

    /** @param list<string> $warnings */
    private function withWarnings(TrackerProjectDefaultResolution $resolution, array $warnings): TrackerProjectDefaultResolution
    {
        return $warnings === [] ? $resolution : $resolution->withAmbiguityWarning(implode(' ', $warnings));
    }

    /**
     * @param  list<TrackerProjectDefaultResolution>  $resolutions
     * @return list<string>
     */
    private function collectWarnings(array $resolutions): array
    {
        $warnings = [];

        foreach ($resolutions as $resolution) {
            if ($resolution->ambiguityWarning !== null && ! in_array($resolution->ambiguityWarning, $warnings, true)) {
                $warnings[] = $resolution->ambiguityWarning;
            }
        }

        return $warnings;
    }
}
