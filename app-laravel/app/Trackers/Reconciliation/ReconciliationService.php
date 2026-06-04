<?php

namespace App\Trackers\Reconciliation;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Integrations\SystemIntegrationRuntime;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\WorkItemLink;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\ReconciliationCandidateDto;
use Illuminate\Database\DatabaseManager;

final class ReconciliationService
{
    public function __construct(
        private readonly OperatorIntegrationRuntime $operatorRuntime,
        private readonly SystemIntegrationRuntime $systemRuntime,
        private readonly DatabaseManager $db,
        private readonly Recorder $recorder,
    ) {}

    /** @return list<ReconciliationResult> */
    public function reconcileEvent(SecurityEvent $event, int $operatorUserId): array
    {
        $pairs = $this->scopedTrackerPairsForEvent($event);

        if ($pairs === []) {
            $pairs = $this->allTrackerPairs();
        }

        if ($pairs === []) {
            return [];
        }

        $index = EventUrlIndex::build([$event]);
        $results = [];

        foreach ($pairs as $pair) {
            $trackerId = $pair['tracker_id'];
            $projectKey = $pair['project_key'];

            try {
                $tracker = $this->operatorRuntime->tracker($trackerId);

                if (! $tracker instanceof Tracker) {
                    continue;
                }

                /** @var list<ReconciliationCandidateDto> $candidates */
                $candidates = $this->operatorRuntime->runTracker(
                    $trackerId,
                    $operatorUserId,
                    fn (Tracker $tracker): array => iterator_to_array($tracker->reconciliationCandidates($projectKey), false),
                );
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($candidates as $candidate) {
                foreach ($this->matchEventIdsForCandidate($index, $candidate) as $eventId) {
                    if ($this->linkExists($eventId, $trackerId, $candidate->workItemId)) {
                        $results[] = ReconciliationResult::alreadyLinked($trackerId, $candidate->workItemId, $eventId);

                        continue;
                    }

                    $this->createLink(
                        eventId: $eventId,
                        trackerId: $trackerId,
                        candidate: $candidate,
                        createdByUserId: $operatorUserId,
                    );

                    $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $eventId, [
                        'tracker_id' => $trackerId,
                        'work_item_id' => $candidate->workItemId,
                        'via' => 'reconciliation',
                        'operator_user_id' => $operatorUserId,
                    ]);

                    $results[] = ReconciliationResult::linked($trackerId, $candidate->workItemId, [$eventId]);
                }
            }
        }

        return $results;
    }

    /** @return list<ReconciliationResult> */
    public function reconcileAll(): array
    {
        if (SecurityEvent::query()->count() === 0) {
            return [];
        }

        $pairs = $this->allTrackerPairs();

        if ($pairs === []) {
            return [];
        }

        $index = EventUrlIndex::build(SecurityEvent::query()->orderBy('id')->lazyById(1000));
        $results = [];

        foreach ($pairs as $pair) {
            $trackerId = $pair['tracker_id'];
            $projectKey = $pair['project_key'];

            try {
                $tracker = $this->systemRuntime->tracker($trackerId);

                if (! $tracker instanceof Tracker) {
                    continue;
                }

                /** @var list<ReconciliationCandidateDto> $candidates */
                $candidates = $this->systemRuntime->runTracker(
                    $trackerId,
                    fn (Tracker $tracker): array => iterator_to_array($tracker->reconciliationCandidates($projectKey), false),
                );
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($candidates as $candidate) {
                foreach ($this->matchEventIdsForCandidate($index, $candidate) as $eventId) {
                    if ($this->linkExists($eventId, $trackerId, $candidate->workItemId)) {
                        $results[] = ReconciliationResult::alreadyLinked($trackerId, $candidate->workItemId, $eventId);

                        continue;
                    }

                    $this->createLink(
                        eventId: $eventId,
                        trackerId: $trackerId,
                        candidate: $candidate,
                        createdByUserId: null,
                    );

                    $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $eventId, [
                        'tracker_id' => $trackerId,
                        'work_item_id' => $candidate->workItemId,
                        'via' => 'reconciliation',
                        'scope' => 'system',
                    ]);

                    $results[] = ReconciliationResult::linked($trackerId, $candidate->workItemId, [$eventId]);
                }
            }
        }

        return $results;
    }

    /** @return list<array{tracker_id: string, project_key: string}> */
    private function scopedTrackerPairsForEvent(SecurityEvent $event): array
    {
        $softwareSystemId = $event->getAttribute('software_system_id');
        $containerId = $event->getAttribute('container_id');

        $query = TrackerProjectLink::query()
            ->select(['tracker_id', 'project_key'])
            ->distinct()
            ->where(function ($scope) use ($softwareSystemId, $containerId): void {
                if (is_int($softwareSystemId)) {
                    $scope->orWhere(function ($inner) use ($softwareSystemId): void {
                        $inner->where('owner_type', SoftwareSystem::class)
                            ->where('owner_id', $softwareSystemId);
                    });
                }

                if (is_int($containerId)) {
                    $scope->orWhere(function ($inner) use ($containerId): void {
                        $inner->where('owner_type', SecurityContainer::class)
                            ->where('owner_id', $containerId);
                    });
                }
            });

        return array_values($query
            ->get()
            ->map(fn (TrackerProjectLink $link): array => [
                'tracker_id' => (string) $link->tracker_id,
                'project_key' => (string) $link->project_key,
            ])
            ->values()
            ->all());
    }

    /** @return list<array{tracker_id: string, project_key: string}> */
    private function allTrackerPairs(): array
    {
        return array_values(TrackerProjectLink::query()
            ->select(['tracker_id', 'project_key'])
            ->distinct()
            ->get()
            ->map(fn (TrackerProjectLink $link): array => [
                'tracker_id' => (string) $link->tracker_id,
                'project_key' => (string) $link->project_key,
            ])
            ->values()
            ->all());
    }

    /** @return list<int> */
    private function matchEventIdsForCandidate(EventUrlIndex $index, ReconciliationCandidateDto $candidate): array
    {
        $matchedEventIds = [];

        foreach ($candidate->extractedUrls as $url) {
            if ($url === '') {
                continue;
            }

            foreach ($index->findAll($url) as $eventId) {
                if (! in_array($eventId, $matchedEventIds, true)) {
                    $matchedEventIds[] = $eventId;
                }
            }
        }

        return $matchedEventIds;
    }

    private function linkExists(int $eventId, string $trackerId, string $workItemId): bool
    {
        return WorkItemLink::query()
            ->where('event_id', $eventId)
            ->where('tracker_id', $trackerId)
            ->where('work_item_id', $workItemId)
            ->exists();
    }

    private function createLink(int $eventId, string $trackerId, ReconciliationCandidateDto $candidate, ?int $createdByUserId): void
    {
        $this->db->transaction(function () use ($eventId, $trackerId, $candidate, $createdByUserId): void {
            WorkItemLink::query()->create([
                'event_id' => $eventId,
                'tracker_id' => $trackerId,
                'work_item_id' => $candidate->workItemId,
                'work_item_url' => $candidate->workItemUrl,
                'work_item_title' => $candidate->title,
                'work_item_state' => $candidate->state,
                'created_by_user_id' => $createdByUserId,
                'created_at' => now(),
                'synced_at' => now(),
            ]);
        });
    }
}
