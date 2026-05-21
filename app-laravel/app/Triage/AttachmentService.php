<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;

final class AttachmentService
{
    public function __construct(private readonly Recorder $recorder) {}

    public function attachToEvent(
        SecurityEvent $event,
        string $kind,
        string $mime,
        string $name,
        string $payload,
        ?int $createdByUserId = null,
        ?string $createdByCommand = null,
    ): EventAttachment {
        return DB::transaction(function () use ($createdByCommand, $createdByUserId, $event, $kind, $mime, $name, $payload): EventAttachment {
            $attachment = EventAttachment::query()->create([
                'event_id' => $event->id,
                'kind' => $kind,
                'mime' => $mime,
                'name' => $name,
                'payload' => $payload,
                'size_bytes' => strlen($payload),
                'created_at' => now(),
                'created_by_user_id' => $createdByUserId,
                'created_by_command' => $createdByCommand,
            ]);

            $this->recorder->recordAttachmentCreated(SecurityEvent::class, (string) $event->id, [
                'attachment_id' => $attachment->id,
                'kind' => $kind,
                'name' => $name,
                'size_bytes' => $attachment->size_bytes,
                'created_by_command' => $createdByCommand,
            ]);

            return $attachment;
        });
    }

    public function delete(EventAttachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $eventId = (string) $attachment->event_id;
            $attachmentId = $attachment->id;
            $attachment->delete();

            $this->recorder->recordAttachmentDeleted(SecurityEvent::class, $eventId, [
                'attachment_id' => $attachmentId,
            ]);
        });
    }
}
