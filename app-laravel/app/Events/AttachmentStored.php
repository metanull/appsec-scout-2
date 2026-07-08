<?php

namespace App\Events;

use App\Models\Attachment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttachmentStored
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Attachment $attachment) {}
}
