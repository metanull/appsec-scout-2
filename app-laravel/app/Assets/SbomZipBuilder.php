<?php

declare(strict_types=1);

namespace App\Assets;

use App\Assets\Sbom\ContainerSbomMerger;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Bundles every descendant container's complete SBOM (its latest lock-file and committed-archive
 * reports merged, see ContainerSbomMerger) for a System or Asset into a single zip file, since
 * SBOM data is only ever collected at the container level.
 */
final class SbomZipBuilder
{
    private const KINDS = [AttachmentIngestionService::KIND_SBOM, AttachmentIngestionService::KIND_SBOM_ARCHIVES];

    public function __construct(
        private readonly ContainerHierarchyResolver $containers,
        private readonly ContainerSbomMerger $sboms,
    ) {}

    public function hasSbomAttachment(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): bool
    {
        if ($owner instanceof SecurityContainer) {
            return $this->sboms->hasSbom($owner);
        }

        return Attachment::query()
            ->whereIn('kind', self::KINDS)
            ->where('owner_type', SecurityContainer::class)
            ->whereIn('owner_id', $this->containers->containersFor($owner)->pluck('id'))
            ->exists();
    }

    public function build(SoftwareSystem|SoftwareAsset $owner): ?string
    {
        $entries = [];

        foreach ($this->containers->containersFor($owner) as $container) {
            $sbom = $this->sboms->forContainer($container);

            if ($sbom === null) {
                continue;
            }

            $entries[$this->sanitizeEntryName($container->name)] = $sbom;
        }

        if ($entries === []) {
            return null;
        }

        Storage::disk('local')->makeDirectory('tmp');
        $path = Storage::disk('local')->path('tmp/' . Str::uuid() . '.zip');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $entryName => $payload) {
            $zip->addFromString($entryName, $payload);
        }

        $zip->close();

        return $path;
    }

    private function sanitizeEntryName(string $name): string
    {
        return Str::slug($name) . '.json';
    }
}
