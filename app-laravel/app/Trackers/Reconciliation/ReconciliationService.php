<?php

namespace App\Trackers\Reconciliation;

use App\Audit\Recorder;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\TrackerProjectLink;
use App\Models\WorkItemLink;
use App\Sync\SystemIntegrationRuntime;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\ReconcileAllJob;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\TrackerProjectLinker;
use App\Triage\OperatorIntegrationRuntime;
use Exception;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Links alerts to existing tracker work items by matching alert URLs found in the text of
 * every recent labelled work item, gathered with one tracker-wide search per tracker.
 *
 * Tracker Project Links never decide what is searched. Reconciliation only learns them: each
 * matched work item reveals the project it belongs to, which becomes a link of the alert's
 * System and Container.
 *
 * The credential flow follows the trigger and is never mixed: the sweep is a system job and
 * uses system credentials only; a per-alert reconciliation is user-triggered and uses only
 * that user's own credentials.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly OperatorIntegrationRuntime $operatorRuntime,
        private readonly SystemIntegrationRuntime $systemRuntime,
        private readonly TrackerRegistry $trackers,
        private readonly TrackerProjectLinker $trackerProjectLinker,
        private readonly DatabaseManager $db,
        private readonly Recorder $recorder,
    ) {}

    /**
     * Searches every tracker the operator has configured with their own credentials. A
     * failing tracker fails the whole reconciliation.
     *
     * @return list<ReconciliationResult>
     *
     * @throws RuntimeException when the operator has no tracker credentials of their own
     */
    public function reconcileEvent(SecurityEvent $event, int $operatorUserId): array
    {
        $trackers = array_values(array_filter(
            $this->trackers->all(),
            fn (Tracker $tracker): bool => $this->operatorRuntime->missingTrackerCredentialLabels($tracker->id(), $operatorUserId) === [],
        ));

        if ($trackers === []) {
            throw new RuntimeException('No tracker is configured with your own credentials. Add one under Profile → Integrations to search it for existing work items.');
        }

        $index = EventUrlIndex::build([$event]);
        $results = [];

        foreach ($trackers as $tracker) {
            /** @var list<ReconciliationCandidateDto> $candidates */
            $candidates = $this->operatorRuntime->runTracker(
                $tracker->id(),
                $operatorUserId,
                fn (Tracker $tracker): array => iterator_to_array($tracker->reconciliationCandidates(), false),
            );

            array_push($results, ...$this->linkCandidates($index, $tracker->id(), $candidates, $operatorUserId));
        }

        return $results;
    }

    /**
     * Sweeps every tracker configured with system credentials for work items referencing any
     * alert. A tracker whose search fails is recorded in the error log and returned as a
     * failure, and the sweep carries on with the remaining trackers.
     */
    public function reconcileAll(): ReconciliationSweep
    {
        if (SecurityEvent::query()->count() === 0) {
            return new ReconciliationSweep([], 0, []);
        }

        $trackers = array_values(array_filter(
            $this->trackers->all(),
            fn (Tracker $tracker): bool => $this->systemRuntime->hasRequiredSystemCredentials($tracker->credentialFields()),
        ));

        if ($trackers === []) {
            return new ReconciliationSweep([], 0, []);
        }

        $index = EventUrlIndex::build(SecurityEvent::query()->orderBy('id')->lazyById(1000));
        $results = [];
        $failures = [];
        $trackersReconciled = 0;

        foreach ($trackers as $tracker) {
            try {
                /** @var list<ReconciliationCandidateDto> $candidates */
                $candidates = $this->systemRuntime->runTracker(
                    $tracker->id(),
                    fn (Tracker $tracker): array => iterator_to_array($tracker->reconciliationCandidates(), false),
                );
            } catch (Exception $exception) {
                $failures[] = $this->recordSweepFailure(new ReconciliationFailure($tracker->id(), $exception));

                continue;
            }

            $trackersReconciled++;
            array_push($results, ...$this->linkCandidates($index, $tracker->id(), $candidates, null));
        }

        return new ReconciliationSweep($results, $trackersReconciled, $failures);
    }

    /**
     * Links every alert a candidate references, and learns the candidate's project as a
     * Tracker Project Link of each matched alert — for an existing work item link too, so
     * links made before learning existed are covered as well.
     *
     * @param  list<ReconciliationCandidateDto>  $candidates
     * @param  ?int  $userId  the operator of a user-triggered reconciliation; null for the sweep
     * @return list<ReconciliationResult>
     */
    private function linkCandidates(EventUrlIndex $index, string $trackerId, array $candidates, ?int $userId): array
    {
        $results = [];

        foreach ($candidates as $candidate) {
            foreach ($this->matchEventIdsForCandidate($index, $candidate) as $eventId) {
                if ($this->linkExists($eventId, $trackerId, $candidate->workItemId)) {
                    $results[] = ReconciliationResult::alreadyLinked($trackerId, $candidate->workItemId, $eventId);
                } else {
                    $this->createLink($eventId, $trackerId, $candidate, $userId);

                    $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $eventId, [
                        'tracker_id' => $trackerId,
                        'work_item_id' => $candidate->workItemId,
                        'via' => 'reconciliation',
                        ...($userId === null ? ['scope' => 'system'] : ['operator_user_id' => $userId]),
                    ]);

                    $results[] = ReconciliationResult::linked($trackerId, $candidate->workItemId, [$eventId]);
                }

                $this->learnProjectLink($eventId, $trackerId, $candidate, $userId);
            }
        }

        return $results;
    }

    private function learnProjectLink(int $eventId, string $trackerId, ReconciliationCandidateDto $candidate, ?int $userId): void
    {
        $project = $candidate->project;
        $event = SecurityEvent::query()->find($eventId);

        if ($project === null || ! $event instanceof SecurityEvent) {
            return;
        }

        $this->trackerProjectLinker->learnFromEvents(
            [$event],
            $trackerId,
            $project->key,
            $project->name,
            $userId,
            $project->url,
            TrackerProjectLink::ORIGIN_RECONCILIATION,
        );
    }

    private function recordSweepFailure(ReconciliationFailure $failure): ReconciliationFailure
    {
        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'reconciliation',
            'message' => $failure->describe(),
            'context_json' => [
                'source_id' => ReconcileAllJob::RUN_SOURCE_ID,
                'tracker_id' => $failure->trackerId,
                'exception' => $failure->exception::class,
            ],
            'trace' => $failure->exception->getTraceAsString(),
            'occurred_at' => now(),
        ]);

        return $failure;
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
