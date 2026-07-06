<?php

namespace App\Http\Controllers;

use App\Assets\FindingsZipBuilder;
use App\Models\SoftwareAsset;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SoftwareAssetFindingsZipDownloadController
{
    public function __construct(private readonly FindingsZipBuilder $builder) {}

    public function __invoke(SoftwareAsset $asset): BinaryFileResponse
    {
        Gate::authorize('alerts.view');

        $path = $this->builder->build($asset);

        abort_if($path === null, 404);

        return response()->download($path, sprintf('%s-findings.zip', $asset->name))->deleteFileAfterSend(true);
    }
}
