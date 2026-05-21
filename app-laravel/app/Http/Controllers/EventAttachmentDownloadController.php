<?php

namespace App\Http\Controllers;

use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EventAttachmentDownloadController
{
    public function __invoke(SecurityEvent $event, EventAttachment $attachment): Response
    {
        Gate::authorize('alerts.view');

        abort_unless($attachment->event_id === $event->id, 404);

        return response($attachment->payload, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($attachment->name)),
        ]);
    }
}
