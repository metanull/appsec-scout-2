<?php

namespace App\Listeners;

use App\Assets\AttachmentIngestionService;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Assets\DependencyTrack\DependencyTrackExporter;
use App\Credentials\Vault;
use App\Events\AttachmentStored;
use App\Models\SecurityContainer;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pushes a freshly stored SBOM straight to Dependency-Track, scoped to the one
 * container it belongs to — not the full resync that `sbom:export-dependency-track`
 * performs. A no-op for any other attachment kind, any non-container owner, or when
 * Dependency-Track isn't configured yet.
 */
class PushSbomAttachmentToDependencyTrack implements ShouldQueue
{
    public function __construct(
        private readonly DependencyTrackClientFactory $clientFactory,
        private readonly Vault $vault,
    ) {}

    public function handle(AttachmentStored $event): void
    {
        $attachment = $event->attachment;

        if ($attachment->kind !== AttachmentIngestionService::KIND_SBOM) {
            return;
        }

        $owner = $attachment->owner;

        if (! $owner instanceof SecurityContainer) {
            return;
        }

        $apiKey = $this->vault->get('dependencytrack.apiKey', null);

        if ($apiKey === null) {
            return;
        }

        $baseUrl = $this->vault->get('dependencytrack.baseUrl', null) ?? 'http://dependencytrack-apiserver:8080';

        $exporter = new DependencyTrackExporter($this->clientFactory->make($apiKey, $baseUrl));
        $exporter->export($owner, 'latest');
    }
}
