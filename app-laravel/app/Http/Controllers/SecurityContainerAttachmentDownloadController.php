<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\SecurityContainer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SecurityContainerAttachmentDownloadController
{
    public function __invoke(SecurityContainer $container, Attachment $attachment): Response
    {
        Gate::authorize('alerts.view');

        abort_unless(
            $attachment->owner_type === SecurityContainer::class && $attachment->owner_id === $container->id,
            404,
        );

        return response($attachment->payload, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($attachment->name)),
        ]);
    }
}
