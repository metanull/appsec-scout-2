<?php

declare(strict_types=1);

namespace App\Assets;

use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Bundles every descendant container's raw vulnerability/secret SARIF
 * attachments for a Container, System, or Asset into a single zip file. A
 * container can have up to two matching attachments (one per LocalFinding
 * kind), so zipping is used at every level rather than a single-file
 * shortcut like SbomZipBuilder uses for containers.
 */
final class FindingsZipBuilder
{
    private const ATTACHMENT_KINDS = ['vulnerabilities', 'secrets'];

    public function __construct(private readonly ContainerHierarchyResolver $containers) {}

    public function hasFindingAttachment(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): bool
    {
        return $this->attachmentsFor($owner)->isNotEmpty();
    }

    public function build(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): ?string
    {
        $entries = [];

        foreach ($this->attachmentsFor($owner) as $attachment) {
            $container = $attachment->owner;

            if (! $container instanceof SecurityContainer) {
                continue;
            }

            $entries[$this->sanitizeEntryName($container->name, (string) $attachment->kind)] = $attachment->payload;
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

    /** @return Collection<int, Attachment> */
    private function attachmentsFor(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): Collection
    {
        $containerIds = $owner instanceof SecurityContainer
            ? [$owner->id]
            : $this->containers->containersFor($owner)->pluck('id')->all();

        return Attachment::query()
            ->where('owner_type', SecurityContainer::class)
            ->whereIn('owner_id', $containerIds)
            ->whereIn('kind', self::ATTACHMENT_KINDS)
            ->with('owner')
            ->get();
    }

    private function sanitizeEntryName(string $containerName, string $kind): string
    {
        return Str::slug($containerName) . '-' . Str::slug($kind) . '.sarif';
    }
}
