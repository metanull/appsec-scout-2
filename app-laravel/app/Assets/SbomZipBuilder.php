<?php

declare(strict_types=1);

namespace App\Assets;

use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Bundles every descendant container's most recent SBOM attachment for a
 * System or Asset into a single zip file, since SBOM data is only ever
 * collected at the container level.
 */
final class SbomZipBuilder
{
    public function __construct(private readonly ContainerHierarchyResolver $containers) {}

    public function hasSbomAttachment(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): bool
    {
        if ($owner instanceof SecurityContainer) {
            return $owner->attachments()->where('kind', 'sbom')->exists();
        }

        return Attachment::query()
            ->where('kind', 'sbom')
            ->where('owner_type', SecurityContainer::class)
            ->whereIn('owner_id', $this->containers->containersFor($owner)->pluck('id'))
            ->exists();
    }

    public function latestAttachmentId(SecurityContainer $container): ?int
    {
        $id = $container->attachments()->where('kind', 'sbom')->latest('created_at')->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function build(SoftwareSystem|SoftwareAsset $owner): ?string
    {
        $entries = [];

        foreach ($this->containers->containersFor($owner) as $container) {
            $attachment = $container->attachments()->where('kind', 'sbom')->latest('created_at')->first();

            if ($attachment === null) {
                continue;
            }

            $entries[$this->sanitizeEntryName($container->name)] = $attachment->payload;
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
