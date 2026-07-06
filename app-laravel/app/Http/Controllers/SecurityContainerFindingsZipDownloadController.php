<?php

namespace App\Http\Controllers;

use App\Assets\FindingsZipBuilder;
use App\Models\SecurityContainer;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SecurityContainerFindingsZipDownloadController
{
    public function __construct(private readonly FindingsZipBuilder $builder) {}

    public function __invoke(SecurityContainer $container): BinaryFileResponse
    {
        Gate::authorize('alerts.view');

        $path = $this->builder->build($container);

        abort_if($path === null, 404);

        return response()->download($path, sprintf('%s-findings.zip', $container->name))->deleteFileAfterSend(true);
    }
}
