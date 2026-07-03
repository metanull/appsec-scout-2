<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\SoftwareAsset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SoftwareAssetAttachmentDownloadController
{
    public function __invoke(SoftwareAsset $asset, Attachment $attachment): Response
    {
        Gate::authorize('alerts.view');

        abort_unless(
            $attachment->owner_type === SoftwareAsset::class && $attachment->owner_id === $asset->id,
            404,
        );

        return response($attachment->payload, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($attachment->name)),
        ]);
    }
}
