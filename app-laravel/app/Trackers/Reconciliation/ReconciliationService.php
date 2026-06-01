<?php

namespace App\Trackers\Reconciliation;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Integrations\SystemIntegrationRuntime;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Jira\AdfToText;
use Illuminate\Database\DatabaseManager;

final class ReconciliationService
{
    public function __construct(
        private readonly OperatorIntegrationRuntime $operatorRuntime,
        private readonly SystemIntegrationRuntime $systemRuntime,
        private readonly DatabaseManager $db,
        private readonly Recorder $recorder,
    ) {}

    /**
     * Reconcile a single event: find any work item that mentions it and create missing links.
     *
     * @return list<ReconciliationResult>
     */
    public function reconcileEvent(SecurityEvent $event, int $operatorUserId): array
    {
        $candidates = $this->findCandidates($event, $operatorUserId);
        $results = [];

        foreach ($candidates as $candidate) {
            $exists = WorkItemLink::query()
                ->where('event_id', $candidate->eventId)
                ->where('tracker_id', $candidate->trackerId)
                ->where('work_item_id', $candidate->workItemId)
                ->exists();

            if ($exists) {
                $results[] = ReconciliationResult::alreadyLinked(
                    $candidate->trackerId,
                    $candidate->workItemId,
                    $candidate->eventId,
                );

                continue;
            }

            $tracker = $this->operatorRuntime->tracker($candidate->trackerId);

            if ($tracker === null) {
                continue;
            }

            $workItem = $this->operatorRuntime->runTracker(
                $candidate->trackerId,
                $operatorUserId,
                fn (Tracker $tracker) => $tracker->getWorkItem($candidate->workItemId),
            );

            if ($workItem === null) {
                continue;
            }

            $this->db->transaction(function () use ($candidate, $operatorUserId, $workItem): void {
                WorkItemLink::query()->create([
                    'event_id' => $candidate->eventId,
                    'tracker_id' => $candidate->trackerId,
                    'work_item_id' => $workItem->id,
                    'work_item_url' => $workItem->url,
                    'work_item_title' => $workItem->title,
                    'work_item_state' => $workItem->state,
                    'created_by_user_id' => $operatorUserId,
                    'created_at' => now(),
                    'synced_at' => now(),
                ]);
            });

            $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $candidate->eventId, [
                'tracker_id' => $candidate->trackerId,
                'work_item_id' => $workItem->id,
                'via' => 'reconciliation',
                'operator_user_id' => $operatorUserId,
            ]);

            $results[] = ReconciliationResult::linked(
                $candidate->trackerId,
                $candidate->workItemId,
                [$candidate->eventId],
            );
        }

        return $results;
    }

    /**
     * Reconcile all events: look at existing work item links and find other events that share the same tracker URL references.
     *
     * @return list<ReconciliationResult>
     */
    public function reconcileAll(): array
    {
        $allResults = [];

        $eventIds = SecurityEvent::query()->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = SecurityEvent::query()->find($eventId);

            if (! $event instanceof SecurityEvent) {
                continue;
            }

            $results = $this->reconcileEventAsSystem($event);
            foreach ($results as $result) {
                $allResults[] = $result;
            }
        }

        return $allResults;
    }

    /** @return list<ReconciliationResult> */
    private function reconcileEventAsSystem(SecurityEvent $event): array
    {
        $candidates = $this->findCandidates($event, null);
        $results = [];

        foreach ($candidates as $candidate) {
            $exists = WorkItemLink::query()
                ->where('event_id', $candidate->eventId)
                ->where('tracker_id', $candidate->trackerId)
                ->where('work_item_id', $candidate->workItemId)
                ->exists();

            if ($exists) {
                $results[] = ReconciliationResult::alreadyLinked(
                    $candidate->trackerId,
                    $candidate->workItemId,
                    $candidate->eventId,
                );

                continue;
            }

            $tracker = $this->systemRuntime->tracker($candidate->trackerId);

            if ($tracker === null) {
                continue;
            }

            $workItem = $this->systemRuntime->runTracker(
                $candidate->trackerId,
                fn (Tracker $tracker) => $tracker->getWorkItem($candidate->workItemId),
            );

            if ($workItem === null) {
                continue;
            }

            $this->db->transaction(function () use ($candidate, $workItem): void {
                WorkItemLink::query()->create([
                    'event_id' => $candidate->eventId,
                    'tracker_id' => $candidate->trackerId,
                    'work_item_id' => $workItem->id,
                    'work_item_url' => $workItem->url,
                    'work_item_title' => $workItem->title,
                    'work_item_state' => $workItem->state,
                    'created_by_user_id' => null,
                    'created_at' => now(),
                    'synced_at' => now(),
                ]);
            });

            $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $candidate->eventId, [
                'tracker_id' => $candidate->trackerId,
                'work_item_id' => $workItem->id,
                'via' => 'reconciliation',
                'scope' => 'system',
            ]);

            $results[] = ReconciliationResult::linked(
                $candidate->trackerId,
                $candidate->workItemId,
                [$candidate->eventId],
            );
        }

        return $results;
    }

    /**
     * Find reconciliation candidates for a given event.
     * Searches existing work_item_links for URL cross-references and also checks tracker for URL-referenced IDs.
     *
     * @return list<ReconciliationCandidate>
     */
    private function findCandidates(SecurityEvent $event, ?int $operatorUserId): array
    {
        $eventUrls = $this->extractEventUrls($event);

        if ($eventUrls === []) {
            return [];
        }

        $candidates = [];
        $seen = [];

        // Strategy 1: Find work_item_links where work_item_url matches one of the event's URLs
        $links = WorkItemLink::query()
            ->whereIn('work_item_url', $eventUrls)
            ->get(['tracker_id', 'work_item_id']);

        foreach ($links as $link) {
            $key = $link->tracker_id . ':' . $link->work_item_id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = new ReconciliationCandidate(
                eventId: $event->id,
                trackerId: (string) $link->tracker_id,
                workItemId: (string) $link->work_item_id,
            );
        }

        // Strategy 2: Check the event URL against known work item URLs (reverse lookup)
        // Find other events that have work item links and check if those work items mention this event
        $eventUrl = is_string($event->url) ? $event->url : null;

        if ($eventUrl !== null) {
            $otherLinks = WorkItemLink::query()
                ->where('work_item_url', '!=', '')
                ->whereNotNull('work_item_url')
                ->get(['tracker_id', 'work_item_id', 'work_item_url']);

            foreach ($otherLinks as $otherLink) {
                $key = $otherLink->tracker_id . ':' . $otherLink->work_item_id;

                if (isset($seen[$key])) {
                    continue;
                }

                // Check if work item description contains the event URL
                $trackerId = (string) $otherLink->tracker_id;
                $tracker = $operatorUserId !== null
                    ? $this->operatorRuntime->tracker($trackerId)
                    : $this->systemRuntime->tracker($trackerId);

                if ($tracker === null) {
                    continue;
                }

                $workItem = $operatorUserId !== null
                    ? $this->operatorRuntime->runTracker($trackerId, $operatorUserId, fn (Tracker $tracker) => $tracker->getWorkItem((string) $otherLink->work_item_id))
                    : $this->systemRuntime->runTracker($trackerId, fn (Tracker $tracker) => $tracker->getWorkItem((string) $otherLink->work_item_id));

                if ($workItem === null) {
                    continue;
                }

                if ($workItem->description !== null && str_contains($workItem->description, $eventUrl)) {
                    $seen[$key] = true;
                    $candidates[] = new ReconciliationCandidate(
                        eventId: $event->id,
                        trackerId: (string) $otherLink->tracker_id,
                        workItemId: (string) $otherLink->work_item_id,
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * Extract all HTTP/HTTPS URLs associated with an event.
     *
     * @return list<string>
     */
    private function extractEventUrls(SecurityEvent $event): array
    {
        $urls = [];

        if (is_string($event->url) && str_starts_with($event->url, 'http')) {
            $urls[] = $event->url;
        }

        if (is_string($event->version_control_url) && str_starts_with($event->version_control_url, 'http')) {
            $urls[] = $event->version_control_url;
        }

        /** @var array<string, mixed>|null $metadata */
        $metadata = $event->getAttribute('metadata');

        if (is_array($metadata)) {
            foreach (AdfToText::urlsFromText(json_encode($metadata) ?: '') as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
