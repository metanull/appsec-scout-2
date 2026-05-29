<?php

namespace App\Trackers\Reconciliation;

use App\Audit\Recorder;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Trackers\Jira\AdfToText;
use App\Trackers\Registry;
use Illuminate\Database\DatabaseManager;

final class ReconciliationService
{
    public function __construct(
        private readonly Registry $registry,
        private readonly DatabaseManager $db,
        private readonly Recorder $recorder,
    ) {}

    /**
     * Reconcile a single event: find any work item that mentions it and create missing links.
     *
     * @return list<ReconciliationResult>
     */
    public function reconcileEvent(SecurityEvent $event): array
    {
        $candidates = $this->findCandidates($event);
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

            $tracker = $this->registry->find($candidate->trackerId);

            if ($tracker === null) {
                continue;
            }

            $workItem = $tracker->getWorkItem($candidate->workItemId);

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

            $results = $this->reconcileEvent($event);
            foreach ($results as $result) {
                $allResults[] = $result;
            }
        }

        return $allResults;
    }

    /**
     * Find reconciliation candidates for a given event.
     * Searches existing work_item_links for URL cross-references and also checks tracker for URL-referenced IDs.
     *
     * @return list<ReconciliationCandidate>
     */
    private function findCandidates(SecurityEvent $event): array
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
                $tracker = $this->registry->find((string) $otherLink->tracker_id);

                if ($tracker === null) {
                    continue;
                }

                $workItem = $tracker->getWorkItem((string) $otherLink->work_item_id);

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
