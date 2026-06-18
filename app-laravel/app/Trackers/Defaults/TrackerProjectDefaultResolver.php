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

        $containerResolution = $this->containerResolution($event, $normalizedTrackerId);

        if ($containerResolution instanceof TrackerProjectDefaultResolution) {
            return $containerResolution;
        }

        $systemResolution = $this->systemResolution($event, $normalizedTrackerId);

        if ($systemResolution instanceof TrackerProjectDefaultResolution) {
            return $systemResolution;
        }

        $fallback = $this->trackerFallback($normalizedTrackerId);

        if ($fallback instanceof TrackerProjectDefaultResolution) {
            return $fallback;
        }

        return TrackerProjectDefaultResolution::none(
            trackerId: $normalizedTrackerId,
            reasonText: 'No accepted context mapping or tracker fallback configured for this tracker.',
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

        if ($resolved === []) {
            return TrackerProjectDefaultResolution::none($normalizedTrackerId, 'No alerts were provided for default resolution.');
        }

        if (count($resolved) === 1) {
            return $resolved[0];
        }

        $defaults = array_values(array_filter($resolved, fn (TrackerProjectDefaultResolution $row): bool => $row->hasDefault()));

        if ($defaults === []) {
            return TrackerProjectDefaultResolution::none(
                trackerId: $normalizedTrackerId,
                reasonText: 'No selected alerts resolved to a tracker project default.',
            );
        }

        if (count($defaults) !== count($resolved)) {
            return TrackerProjectDefaultResolution::none(
                trackerId: $normalizedTrackerId,
                reasonText: 'Some selected alerts have no project default, so no grouped default was applied.',
            );
        }

        $first = $defaults[0];

        foreach ($defaults as $candidate) {
            if ($candidate->projectKey !== $first->projectKey) {
                return TrackerProjectDefaultResolution::none(
                    trackerId: $normalizedTrackerId,
                    reasonText: 'Selected alerts resolve to different projects, so no grouped default was applied.',
                );
            }
        }

        return TrackerProjectDefaultResolution::resolved(
            trackerId: $first->trackerId,
            projectKey: (string) $first->projectKey,
            projectName: $first->projectName,
            source: $first->source ?? 'context_mapping',
            confidenceLabel: $first->confidenceLabel,
            reasonText: 'All selected alerts resolved to the same project default.',
        );
    }

    private function containerResolution(SecurityEvent $event, string $trackerId): ?TrackerProjectDefaultResolution
    {
        $event->loadMissing('container.trackerProjectLinks');

        $container = $event->getRelationValue('container');

        if ($container === null) {
            return null;
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $links */
        $links = $container->trackerProjectLinks->where('tracker_id', $trackerId)->values();

        return $this->resolveFromLinks(
            links: $links,
            trackerId: $trackerId,
            source: 'container_mapping',
            confidenceLabel: 'Container mapping',
            reasonText: 'Resolved from accepted container tracker mapping.',
        );
    }

    private function systemResolution(SecurityEvent $event, string $trackerId): ?TrackerProjectDefaultResolution
    {
        $event->loadMissing('softwareSystem.trackerProjectLinks');

        $system = $event->getRelationValue('softwareSystem');

        if ($system === null) {
            return null;
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $links */
        $links = $system->trackerProjectLinks->where('tracker_id', $trackerId)->values();

        return $this->resolveFromLinks(
            links: $links,
            trackerId: $trackerId,
            source: 'system_mapping',
            confidenceLabel: 'System mapping',
            reasonText: 'Resolved from accepted system tracker mapping.',
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
     */
    private function resolveFromLinks(
        EloquentCollection $links,
        string $trackerId,
        string $source,
        string $confidenceLabel,
        string $reasonText,
    ): ?TrackerProjectDefaultResolution {
        if ($links->isEmpty()) {
            return null;
        }

        if ($links->count() === 1) {
            $selected = $links->first();

            return TrackerProjectDefaultResolution::resolved(
                trackerId: $trackerId,
                projectKey: $selected->project_key,
                projectName: $selected->project_name,
                source: $source,
                confidenceLabel: $confidenceLabel,
                reasonText: $reasonText,
            );
        }

        /** @var EloquentCollection<int, TrackerProjectLink> $defaults */
        $defaults = $links->filter(fn (TrackerProjectLink $link): bool => (bool) $link->is_default)->values();

        if ($defaults->count() !== 1) {
            return null;
        }

        $selected = $defaults->first();

        if ($selected === null) {
            return null;
        }

        return TrackerProjectDefaultResolution::resolved(
            trackerId: $trackerId,
            projectKey: $selected->project_key,
            projectName: $selected->project_name,
            source: $source,
            confidenceLabel: $confidenceLabel,
            reasonText: $reasonText,
        );
    }
}
