<?php

namespace App\Audit;

use Illuminate\Http\Request;

class Recorder
{
    public function __construct(private readonly ?Request $request = null) {}

    /** @param array<string, mixed> $payload */
    public function recordStateChange(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('state_change', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordBulkStateChange(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('bulk_state_change', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordSeverityChange(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('severity_change', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordCommentAdded(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('comment_added', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordCommentEdited(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('comment_edited', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordSyncPush(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('sync_push', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordRefetch(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('event_refetched', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordWorkItemCreated(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('work_item_created', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordWorkItemLinked(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('work_item_linked', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordWorkItemUnlinked(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('work_item_unlinked', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordTrackerStateChanged(string $subjectType, string $subjectId, array $payload = []): void
    {
        $this->write('tracker_state_changed', $subjectType, $subjectId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordAdminAction(string $action, array $payload = []): void
    {
        $this->write($action, null, null, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function recordCredentialChange(string $integrationKey, string $actor, string $outcome, array $payload = []): void
    {
        $this->write('credential_change', 'credential', $integrationKey, array_merge(
            ['actor' => $actor, 'outcome' => $outcome],
            $payload,
        ));
    }

    /** @param array<string, mixed> $payload */
    private function write(string $action, ?string $subjectType, ?string $subjectId, array $payload): void
    {
        $user = auth()->user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'actor_kind' => $this->resolveActorKind(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload_json' => $payload !== [] ? $payload : null,
            'ip' => $this->request?->ip(),
        ]);
    }

    private function resolveActorKind(): string
    {
        if (app()->runningInConsole()) {
            return 'cli';
        }

        if (auth()->check()) {
            return 'user';
        }

        return 'system';
    }
}
