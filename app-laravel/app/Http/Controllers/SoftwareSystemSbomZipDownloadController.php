<?php

namespace App\Http\Controllers;

use App\Assets\SbomZipBuilder;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SoftwareSystemSbomZipDownloadController
{
    public function __construct(private readonly SbomZipBuilder $builder) {}

    public function __invoke(SoftwareSystem $system): BinaryFileResponse
    {
        Gate::authorize('alerts.view');

        $path = $this->builder->build($system);

        abort_if($path === null, 404);

        return response()->download($path, sprintf('%s-sbom.zip', $system->name))->deleteFileAfterSend(true);
    }
}
