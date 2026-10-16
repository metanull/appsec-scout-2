<?php

namespace App\Trackers;

use App\Audit\Recorder;
use App\Models\ErrorLog;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\WorkItemDto;
use App\Triage\OperatorIntegrationRuntime;
use Illuminate\Validation\ValidationException;

/**
 * The optional Markdown comment an operator adds when creating a work item. It is checked against
 * the tracker's comment size limit before anything is created, then posted on the new work item
 * with the operator's own tracker credential. A failed post leaves the work item and its links in
 * place: the failure is logged, audited and returned so the caller can surface it.
 */
final class WorkItemComment
{
    public function __construct(
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
    ) {}

    /** The comment to post, or null when there is none; refuses one longer than the tracker accepts. */
    public function validated(?string $comment, string $trackerId): ?string
    {
        $comment = $comment !== null ? trim($comment) : '';

        if ($comment === '') {
            return null;
        }

        $tracker = $this->runtime->tracker($trackerId) ?? throw new \RuntimeException("Tracker {$trackerId} is not registered.");
        $max = $tracker->capabilities()->maxCommentChars;
        $length = mb_strlen($comment);

        if ($length > $max) {
            throw ValidationException::withMessages([
                'comment' => sprintf('The comment is %d characters long; %s accepts at most %d. Nothing was created.', $length, $tracker->displayName(), $max),
            ]);
        }

        return $comment;
    }

    /** Posts the comment on the work item; returns null on success, or why it failed. */
    public function post(string $comment, WorkItemDto $workItem, string $trackerId, int $userId, string $subjectType, string $subjectId): ?string
    {
        $audit = [
            'tracker_id' => $trackerId,
            'work_item_id' => $workItem->id,
            'comment' => $comment,
        ];

        try {
            $this->runtime->runTracker($trackerId, $userId, fn (Tracker $tracker) => $tracker->addComment($workItem->id, $comment));
        } catch (\Throwable $e) {
            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'work-items',
                'message' => sprintf('Work item %s was created, but its comment could not be posted: %s', $workItem->id, $e->getMessage()),
                'context_json' => ['tracker_id' => $trackerId, 'work_item_id' => $workItem->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
                'trace' => $e->getTraceAsString(),
                'occurred_at' => now(),
            ]);

            $this->recorder->recordWorkItemCommentAdded($subjectType, $subjectId, $audit + ['status' => 'failure', 'error' => $e->getMessage()]);

            return $e->getMessage();
        }

        $this->recorder->recordWorkItemCommentAdded($subjectType, $subjectId, $audit + ['status' => 'success']);

        return null;
    }
}
