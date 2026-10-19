<?php

namespace App\Listeners;

use App\Assets\AttachmentIngestionService;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Assets\DependencyTrack\DependencyTrackExporter;
use App\Credentials\RunWithSystemCredentials;
use App\Credentials\Vault;
use App\Events\AttachmentStored;
use App\Models\SecurityContainer;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/**
 * Pushes a freshly stored SBOM straight to Dependency-Track, scoped to the one
 * container it belongs to — not the full resync that `sbom:export-dependency-track`
 * performs, with the system Dependency-Track credential. A no-op for any other attachment
 * kind or any non-container owner.
 *
 * Dependency-Track is provisioned by the default stack (dependencytrack-bootstrap), so a
 * missing API key is a misconfiguration: the push fails loudly instead of skipping.
 */
class PushSbomAttachmentToDependencyTrack implements ShouldQueue
{
    public function __construct(
        private readonly DependencyTrackClientFactory $clientFactory,
        private readonly Vault $vault,
    ) {}

    /** @return list<object> */
    public function middleware(AttachmentStored $event): array
    {
        return [new RunWithSystemCredentials];
    }

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

        $apiKey = $this->vault->getSystem('dependencytrack.apiKey')
            ?? throw new RuntimeException('Dependency-Track API key is not configured; run dependencytrack:bootstrap or set it under System Credentials.');

        $baseUrl = $this->vault->getSystem('dependencytrack.baseUrl') ?? 'http://dependencytrack-apiserver:8080';

        $exporter = new DependencyTrackExporter($this->clientFactory->make($apiKey, $baseUrl));
        $exporter->export($owner, 'latest');
    }
}
