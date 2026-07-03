<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\DB;

final class AttachmentService
{
    public function __construct(private readonly Recorder $recorder) {}

    public function attachTo(
        SoftwareAsset|SoftwareSystem|SecurityContainer $owner,
        string $kind,
        string $mime,
        string $name,
        string $payload,
        ?int $createdByUserId = null,
        ?string $createdByCommand = null,
    ): Attachment {
        return DB::transaction(function () use ($createdByCommand, $createdByUserId, $owner, $kind, $mime, $name, $payload): Attachment {
            $attachment = $owner->attachments()->create([
                'kind' => $kind,
                'mime' => $mime,
                'name' => $name,
                'payload' => $payload,
                'size_bytes' => strlen($payload),
                'created_at' => now(),
                'created_by_user_id' => $createdByUserId,
                'created_by_command' => $createdByCommand,
            ]);

            $this->recorder->recordAttachmentCreated($owner::class, (string) $owner->getKey(), [
                'attachment_id' => $attachment->id,
                'kind' => $kind,
                'name' => $name,
                'size_bytes' => $attachment->size_bytes,
                'created_by_command' => $createdByCommand,
            ]);

            return $attachment;
        });
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $ownerType = $attachment->owner_type;
            $ownerId = (string) $attachment->owner_id;
            $attachmentId = $attachment->id;

            $attachment->delete();

            $this->recorder->recordAttachmentDeleted($ownerType, $ownerId, [
                'attachment_id' => $attachmentId,
            ]);
        });
    }
}
