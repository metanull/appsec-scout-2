<?php

namespace App\Http\Controllers;

use App\Assets\Sbom\ContainerSbomMerger;
use App\Models\SecurityContainer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a container's complete SBOM: its latest lock-file (`sbom`) and committed-archive
 * (`sbom-archives`) reports merged into one CycloneDX document.
 */
class SecurityContainerSbomDownloadController
{
    public function __construct(private readonly ContainerSbomMerger $sboms) {}

    public function __invoke(SecurityContainer $container): StreamedResponse
    {
        Gate::authorize('alerts.view');

        $sbom = $this->sboms->forContainer($container);

        abort_if($sbom === null, 404);

        return response()->streamDownload(
            function () use ($sbom): void {
                echo $sbom;
            },
            sprintf('%s.cdx.json', Str::slug($container->name)),
            ['Content-Type' => 'application/vnd.cyclonedx+json'],
        );
    }
}
