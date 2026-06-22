<?php

namespace App\Sync;

use App\Models\SecurityEvent;
use App\Sources\Dto\EventDto;

final class Upserter
{
    /**
     * @param  array<string, int>  $systemIdMap
     * @param  array<string, int>  $containerIdMap
     */
    public function upsert(string $sourceId, EventDto $dto, array $systemIdMap, array $containerIdMap): SecurityEvent
    {
        $softwareSystemId = $systemIdMap[$dto->sourceSystemId] ?? null;

        if (! is_int($softwareSystemId)) {
            throw new \RuntimeException("Missing software system mapping for {$dto->sourceSystemId}");
        }

        $containerId = null;
        if ($dto->sourceContainerId !== null && $dto->sourceContainerId !== '') {
            $containerId = $containerIdMap[$dto->sourceSystemId . ':' . $dto->sourceContainerId] ?? null;
            $containerId ??= $containerIdMap[$dto->sourceContainerId] ?? null;
        }

        $existing = SecurityEvent::query()->where([
            'source_id' => $sourceId,
            'source_event_id' => $dto->sourceEventId,
        ])->first();

        $metadata = $dto->metadata ?? [];

        if ($existing !== null) {
            $existingMetadata = $existing->getAttribute('metadata');

            if (is_array($existingMetadata) && isset($existingMetadata['local'])) {
                $metadata['local'] = $existingMetadata['local'];
            }
        }

        $payload = [
            'source_id' => $sourceId,
            'source_event_id' => $dto->sourceEventId,
            'software_system_id' => $softwareSystemId,
            'container_id' => $containerId,
            'title' => $dto->title,
            'description' => $dto->description,
            'severity' => $dto->severity,
            'state' => $dto->state,
            'type' => $dto->type,
            'rule_id' => $dto->ruleId,
            'fingerprint' => $dto->fingerprint,
            'url' => $dto->url,
            'remediation' => $dto->remediation,
            'file_path' => $dto->filePath,
            'start_line' => $dto->startLine,
            'end_line' => $dto->endLine,
            'snippet' => $dto->snippet,
            'commit_sha' => $dto->commitSha,
            'branch' => $dto->branch,
            'version_control_url' => $dto->versionControlUrl,
            'source_data' => $dto->sourceData,
            'metadata' => $metadata,
            'first_seen_at' => $dto->firstSeenAt,
            'last_seen_at' => $dto->lastSeenAt,
            'synced_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing === null) {
            return SecurityEvent::query()->create(array_merge($payload, [
                'is_dirty' => false,
                'pending_state' => null,
                'pending_severity' => null,
                'pending_comment' => null,
            ]));
        }

        $payload['is_dirty'] = $existing->is_dirty;
        $payload['pending_state'] = $existing->pending_state;
        $payload['pending_severity'] = $existing->pending_severity;
        $payload['pending_comment'] = $existing->pending_comment;

        $existing->update($payload);

        return $existing;
    }
}
