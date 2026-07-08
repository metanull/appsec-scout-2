<?php

namespace App\Listeners;

use App\Assets\AttachmentIngestionService;
use App\Events\AttachmentStored;
use Illuminate\Contracts\Queue\ShouldQueue;

class ParseAttachmentIntoFindings implements ShouldQueue
{
    public function __construct(private readonly AttachmentIngestionService $ingestion) {}

    public function handle(AttachmentStored $event): void
    {
        $this->ingestion->ingest($event->attachment);
    }
}
