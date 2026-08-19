<?php

namespace App\Assets;

use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            LocalFinding::KIND_VULNERABILITY => $this->matchVulnerability($finding, $this->eventsQuery($finding)->where('type', EventType::Dependency->value)->get()),
            LocalFinding::KIND_SECRET => $this->matchSecret($finding, $this->eventsQuery($finding)->where('type', EventType::Secret->value)->get()),
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

    /**
     * Same matching as correlate(), but for a whole batch of findings sharing one owner: the
     * Dependency/Secret candidate SecurityEvents (and, for a SoftwareAsset owner, its
     * softwareSystems() ids) are fetched once for the batch instead of once per finding, and
     * every resulting correlation is written with one chunked upsert() instead of one save()
     * per match.
     *
     * @param  iterable<LocalFinding>  $findings
     */
    public function correlateBatch(iterable $findings, SoftwareAsset|SoftwareSystem|SecurityContainer $owner): void
    {
        $findings = collect($findings);

        if ($findings->isEmpty()) {
            return;
        }

        $softwareSystemIds = $owner instanceof SoftwareAsset ? $owner->softwareSystems()->pluck('id') : null;

        $dependencyEvents = $findings->contains(fn (LocalFinding $finding): bool => $finding->kind === LocalFinding::KIND_VULNERABILITY)
            ? $this->candidateEventsQuery($owner, $softwareSystemIds)->where('type', EventType::Dependency->value)->get()
            : collect();

        $secretEvents = $findings->contains(fn (LocalFinding $finding): bool => $finding->kind === LocalFinding::KIND_SECRET)
            ? $this->candidateEventsQuery($owner, $softwareSystemIds)->where('type', EventType::Secret->value)->get()
            : collect();

        // Grouped by the resulting (event, method) pair rather than upserted by id: upsert()'s
        // INSERT-with-ON-CONFLICT form still validates every NOT NULL column on SQLite/Postgres
        // even though the conflict always redirects to an UPDATE, so a partial-column upsert
        // keyed on id fails there (MySQL's ON DUPLICATE KEY UPDATE is more lenient, but this
        // must stay portable). Grouping means one plain whereIn()->update() per distinct match
        // outcome — typically far fewer than one per finding, since many findings commonly
        // correlate to the same event.
        /** @var array<string, array{eventId: int, method: string, ids: list<int>}> $groups */
        $groups = [];

        foreach ($findings as $finding) {
            $match = match ($finding->kind) {
                LocalFinding::KIND_VULNERABILITY => $this->matchVulnerability($finding, $dependencyEvents),
                LocalFinding::KIND_SECRET => $this->matchSecret($finding, $secretEvents),
                default => null,
            };

            if ($match === null) {
                continue;
            }

            [$event, $method] = $match;
            $groupKey = "{$event->id}|{$method}";

            $groups[$groupKey] ??= ['eventId' => $event->id, 'method' => $method, 'ids' => []];
            $groups[$groupKey]['ids'][] = $finding->id;
        }

        $now = now();

        foreach ($groups as $group) {
            foreach (array_chunk($group['ids'], 500) as $chunk) {
                LocalFinding::query()->whereIn('id', $chunk)->update([
                    'correlated_security_event_id' => $group['eventId'],
                    'correlation_method' => $group['method'],
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param  iterable<SecurityEvent>  $candidates
     * @return array{0: SecurityEvent, 1: string}|null
     */
    private function matchVulnerability(LocalFinding $finding, iterable $candidates): ?array
    {
        if ($finding->package_name === null || $finding->package_version === null) {
            return null;
        }

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

    /**
     * File path is checked here in PHP rather than as a query-level where() (unlike the
     * original single-finding implementation) because correlateBatch() shares one candidate
     * pool across findings that can each have a different file_path — only the line-proximity
     * scan can be shared; the path match must happen per candidate.
     *
     * @param  iterable<SecurityEvent>  $candidates
     * @return array{0: SecurityEvent, 1: string}|null
     */
    private function matchSecret(LocalFinding $finding, iterable $candidates): ?array
    {
        if ($finding->start_line === null) {
            return null;
        }

        foreach ($candidates as $event) {
            if ($event->file_path !== $finding->file_path || $event->start_line === null) {
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

        if (! $owner instanceof SoftwareAsset && ! $owner instanceof SoftwareSystem && ! $owner instanceof SecurityContainer) {
            return SecurityEvent::query()->whereRaw('1 = 0');
        }

        return $this->candidateEventsQuery($owner);
    }

    /**
     * @param  Collection<int, int>|null  $softwareSystemIds  pre-resolved only for a
     *                                                        SoftwareAsset owner, so a batch
     *                                                        call resolves it once instead of
     *                                                        once per finding.
     * @return Builder<SecurityEvent>
     */
    private function candidateEventsQuery(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, ?Collection $softwareSystemIds = null): Builder
    {
        return match (true) {
            $owner instanceof SecurityContainer => SecurityEvent::query()->where('container_id', $owner->id),
            $owner instanceof SoftwareSystem => SecurityEvent::query()->where('software_system_id', $owner->id),
            $owner instanceof SoftwareAsset => SecurityEvent::query()->whereIn(
                'software_system_id',
                $softwareSystemIds ?? $owner->softwareSystems()->pluck('id'),
            ),
        };
    }
}
