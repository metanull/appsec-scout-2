<?php

namespace App\Http\Controllers;

use App\Assets\FindingsZipBuilder;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SoftwareSystemFindingsZipDownloadController
{
    public function __construct(private readonly FindingsZipBuilder $builder) {}

    public function __invoke(SoftwareSystem $system): BinaryFileResponse
    {
        Gate::authorize('alerts.view');

        $path = $this->builder->build($system);

        abort_if($path === null, 404);

        return response()->download($path, sprintf('%s-findings.zip', $system->name))->deleteFileAfterSend(true);
    }
}
