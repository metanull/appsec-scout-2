<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\SoftwareSystem;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SoftwareSystemAttachmentDownloadController
{
    public function __invoke(SoftwareSystem $system, Attachment $attachment): Response
    {
        Gate::authorize('alerts.view');

        abort_unless(
            $attachment->owner_type === SoftwareSystem::class && $attachment->owner_id === $system->id,
            404,
        );

        return response($attachment->payload, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($attachment->name)),
        ]);
    }
}
