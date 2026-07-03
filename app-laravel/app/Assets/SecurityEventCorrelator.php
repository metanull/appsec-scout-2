<?php

namespace App\Assets;

use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Best-effort matching of a locally-scanned finding to an existing
 * SecurityEvent synced from a live source (AzDO/ASoC/Detectify). Only sets a
 * correlation when confident; leaves it unset (never guesses) otherwise.
 *
 * Vulnerabilities correlate on (owner + package name + package version),
 * since Trivy and a source's own dependency scanner rarely agree on the
 * exact line within a lockfile. Secrets correlate on (owner + exact file
 * path + line proximity), since there is no package identity to anchor on.
 *
 * This only works because our pipeline always runs `trivy fs` against a
 * freshly cloned repository root, so Trivy's reported paths are already
 * relative to that root — the same convention AzDO's own file_path uses.
 * A different scan mode (e.g. `trivy image`) would need path normalization
 * before this comparison would be meaningful.
 */
final class SecurityEventCorrelator
{
    private const LINE_PROXIMITY_TOLERANCE = 2;

    public function correlate(LocalFinding $finding): void
    {
        $match = match ($finding->kind) {
            LocalFinding::KIND_VULNERABILITY => $this->correlateVulnerability($finding),
            LocalFinding::KIND_SECRET => $this->correlateSecret($finding),
            default => null,
        };

        if ($match === null) {
            return;
        }

        [$event, $method] = $match;

        $finding->forceFill([
            'correlated_security_event_id' => $event->id,
            'correlation_method' => $method,
        ])->save();
    }

    /** @return array{0: SecurityEvent, 1: string}|null */
    private function correlateVulnerability(LocalFinding $finding): ?array
    {
        if ($finding->package_name === null || $finding->package_version === null) {
            return null;
        }

        $candidates = $this->eventsQuery($finding)->where('type', EventType::Dependency->value)->get();

        foreach ($candidates as $event) {
            $metadata = $event->getAttribute('metadata');
            $package = is_array($metadata) ? ($metadata['package'] ?? null) : null;

            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (is_string($name) && is_string($version)
                && strcasecmp($name, $finding->package_name) === 0
                && $version === $finding->package_version) {
                return [$event, 'package_version'];
            }
        }

        return null;
    }

    /** @return array{0: SecurityEvent, 1: string}|null */
    private function correlateSecret(LocalFinding $finding): ?array
    {
        if ($finding->start_line === null) {
            return null;
        }

        $candidates = $this->eventsQuery($finding)
            ->where('type', EventType::Secret->value)
            ->where('file_path', $finding->file_path)
            ->get();

        foreach ($candidates as $event) {
            if ($event->start_line === null) {
                continue;
            }

            if (abs($event->start_line - $finding->start_line) <= self::LINE_PROXIMITY_TOLERANCE) {
                return [$event, 'file_line'];
            }
        }

        return null;
    }

    /** @return Builder<SecurityEvent> */
    private function eventsQuery(LocalFinding $finding): Builder
    {
        $owner = $finding->owner;

        return match (true) {
            $owner instanceof SecurityContainer => SecurityEvent::query()->where('container_id', $owner->id),
            $owner instanceof SoftwareSystem => SecurityEvent::query()->where('software_system_id', $owner->id),
            $owner instanceof SoftwareAsset => SecurityEvent::query()->whereIn(
                'software_system_id',
                $owner->softwareSystems()->pluck('id'),
            ),
            default => SecurityEvent::query()->whereRaw('1 = 0'),
        };
    }
}
