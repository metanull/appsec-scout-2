<?php

namespace App\Http\Controllers;

use App\Assets\SbomZipBuilder;
use App\Models\SoftwareAsset;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SoftwareAssetSbomZipDownloadController
{
    public function __construct(private readonly SbomZipBuilder $builder) {}

    public function __invoke(SoftwareAsset $asset): BinaryFileResponse
    {
        Gate::authorize('alerts.view');

        $path = $this->builder->build($asset);

        abort_if($path === null, 404);

        return response()->download($path, sprintf('%s-sbom.zip', $asset->name))->deleteFileAfterSend(true);
    }
}
