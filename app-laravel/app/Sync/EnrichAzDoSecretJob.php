<?php

namespace App\Sync;

use App\Credentials\Vault;
use App\Models\SecurityEvent;
use App\Sources\AzDo\AzDoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class EnrichAzDoSecretJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $sourceId,
        public readonly int $eventId,
        public readonly string $projectId,
        public readonly string $repoId,
        public readonly int $alertId,
    ) {}

    public function handle(Vault $vault, ?AzDoClient $clientOverride = null): void
    {
        $event = SecurityEvent::find($this->eventId);

        if ($event === null) {
            return;
        }

        if ($clientOverride !== null) {
            $client = $clientOverride;
        } else {
            $pat  = $vault->get('azdo.pat', null, true) ?? throw new \RuntimeException('AzDO PAT not configured');
            $org  = $vault->get('azdo.organization', null, true) ?? throw new \RuntimeException('AzDO organization not configured');
            $base = $vault->get('azdo.baseUrl', null, true) ?? 'https://dev.azure.com';
            $client = new AzDoClient($org, $pat, $base);
        }
        $alert  = $client->getAlert($this->projectId, $this->repoId, $this->alertId);

        $metadata = is_array($event->getAttribute('metadata')) ? $event->getAttribute('metadata') : [];

        if ($alert->validityDetails !== null) {
            $metadata['validityDetails'] = $alert->validityDetails;
        }

        if ($alert->validationFingerprints !== []) {
            $metadata['validationFingerprints'] = $alert->validationFingerprints;
        }

        $updates = ['metadata' => $metadata];

        // Derive fingerprint from the canonical validation hash when not yet set.
        if ($event->fingerprint === null && $alert->validationFingerprints !== []) {
            $hash = $alert->validationFingerprints[0]['validationFingerprintHash'] ?? null;
            if ($hash !== null) {
                $updates['fingerprint'] = $hash;
            }
        }

        $event->update($updates);
    }
}
