<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

use App\Models\SecurityContainer;
use RuntimeException;

final class DependencyTrackExporter
{
    public function __construct(private readonly DependencyTrackClient $client) {}

    public function export(SecurityContainer $container, string $projectVersion): void
    {
        $attachment = $container->attachments()->where('kind', 'sbom')->latest('created_at')->first();

        if ($attachment === null) {
            throw new RuntimeException(sprintf('No SBOM attachment found for container "%s".', $container->name));
        }

        $this->client->uploadBom($container->name, $projectVersion, self::downgradeUnsupportedSpecVersion($attachment->payload));
    }

    /**
     * Dependency-Track (as of 4.14) rejects CycloneDX specVersion "1.7", which
     * newer Trivy releases emit by default; relabel it as "1.6" so the upload
     * is accepted. The two versions share the same fields Trivy populates
     * (components, purl, name, version, license), so no data is lost.
     */
    private static function downgradeUnsupportedSpecVersion(string $bomPayload): string
    {
        $decoded = json_decode($bomPayload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ($decoded['specVersion'] ?? null) !== '1.7') {
            return $bomPayload;
        }

        $decoded['specVersion'] = '1.6';

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }
}
