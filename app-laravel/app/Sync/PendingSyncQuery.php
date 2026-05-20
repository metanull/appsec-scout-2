<?php

namespace App\Sync;

use App\Audit\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Collection;

final class PendingSyncQuery
{
    /** @return Collection<int, SecurityEvent> */
    public function pending(): Collection
    {
        return SecurityEvent::query()
            ->where('is_dirty', true)
            ->orderBy('source_id')
            ->orderByDesc('updated_at')
            ->get();
    }

    /** @return Collection<string, Collection<int, array<string, mixed>>> */
    public function grouped(): Collection
    {
        $events = $this->pending();
        $eventIds = $events->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        $audits = AuditLog::query()
            ->where('subject_type', SecurityEvent::class)
            ->whereIn('subject_id', $eventIds)
            ->whereNotNull('user_id')
            ->orderByDesc('created_at')
            ->get()
            ->unique('subject_id')
            ->values();

        $userNames = User::query()
            ->whereIn('id', $audits->pluck('user_id')->filter()->all())
            ->pluck('name', 'id');

        /** @var Collection<string, AuditLog> $auditMap */
        $auditMap = $audits->keyBy('subject_id');

        $grouped = $events
            ->map(function (SecurityEvent $event) use ($auditMap, $userNames): array {
                $audit = $auditMap->get((string) $event->id);
                $lastEditorName = null;
                $metadata = $event->getAttribute('metadata');

                if (! is_array($metadata)) {
                    $metadata = [];
                }

                /** @var array<string, mixed> $metadata */
                if ($audit !== null && $audit->user_id !== null) {
                    $lastEditorName = $userNames->get($audit->user_id, 'User #' . $audit->user_id);
                }

                return [
                    'event' => $event,
                    'last_editor_name' => $lastEditorName,
                    'last_edited_at' => $audit !== null ? $audit->created_at : $event->updated_at,
                    'last_error' => is_string($metadata['lastPushError'] ?? null) ? $metadata['lastPushError'] : null,
                    'retry_count' => (int) ($metadata['pushRetryCount'] ?? 0),
                ];
            })
            ->groupBy(fn (array $row): string => $row['event']->source_id)
            ->map(fn (Collection $rows): Collection => $rows->values());

        /** @var Collection<string, Collection<int, array<string, mixed>>> $grouped */
        return $grouped;
    }
}
