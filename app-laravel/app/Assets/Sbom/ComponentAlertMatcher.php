<?php

namespace App\Assets\Sbom;

use App\Database\TextSearch;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Links SBOM components to the source alerts on their package (e.g. AzDO dependency alerts). An
 * alert belongs to a component when it is on the component's container and its package facts name
 * the component's package (ignoring case: AzDO lower-cases NuGet ids) and version. An alert
 * reported against a whole system (no container) matches no component. The package facts live in
 * the alert's JSON metadata, so the query only narrows the candidates with a text search on each
 * package name, and the exact match runs here.
 */
final class ComponentAlertMatcher
{
    /**
     * The matching alerts of a page of components, keyed by component id. One query for the
     * whole page.
     *
     * @param  Collection<int, SoftwareComponent>  $components
     * @param  list<string>  $states  EventState values; empty = any
     * @param  list<string>  $severities  severities incl. "unknown" (which no alert has); empty = any
     * @return array<int, list<SecurityEvent>>
     */
    public function alertsFor(Collection $components, array $states, array $severities): array
    {
        $onContainers = $components->filter(fn (SoftwareComponent $component): bool => $component->owner_type === SecurityContainer::class);
        $names = $onContainers->pluck('name')->filter()->unique()->values();

        if ($names->isEmpty()) {
            return [];
        }

        $query = SecurityEvent::query()
            ->whereIn('container_id', $onContainers->pluck('owner_id')->unique()->values()->all())
            ->where(function (Builder $candidates) use ($names): void {
                foreach ($names as $name) {
                    $candidates->orWhere(fn (Builder $named) => TextSearch::apply($named, ['metadata'], (string) $name, ['metadata']));
                }
            });

        SecurityEventTableQuery::applyStates($query, $states);
        SecurityEventTableQuery::applySeverities($query, $severities);

        $byKey = [];

        foreach ($query->orderBy('id')->get() as $alert) {
            $package = self::package($alert);

            if ($package !== null) {
                $byKey[self::key($alert->container_id, $package['name'], $package['version'])][] = $alert;
            }
        }

        $matches = [];

        foreach ($onContainers as $component) {
            $matches[(int) $component->id] = $byKey[self::key($component->owner_id, (string) $component->name, (string) $component->version)] ?? [];
        }

        return $matches;
    }

    /**
     * The package an alert reports, from its package facts.
     *
     * @return array{name: string, version: string, ecosystem: string|null}|null
     */
    public static function package(SecurityEvent $alert): ?array
    {
        $metadata = self::metadata($alert);
        $name = SourceContextFacts::getString($metadata, SourceContextFacts::PACKAGE_NAME);
        $version = SourceContextFacts::getString($metadata, SourceContextFacts::PACKAGE_VERSION);

        if ($name === null || $version === null) {
            return null;
        }

        return ['name' => $name, 'version' => $version, 'ecosystem' => SourceContextFacts::getString($metadata, SourceContextFacts::PACKAGE_ECOSYSTEM)];
    }

    /**
     * The advisory ids an alert names: its CVE first, then its advisory id (e.g. GHSA). The first
     * is the one Trivy reports the same vulnerability under.
     *
     * @return list<string>
     */
    public static function advisoryIds(SecurityEvent $alert): array
    {
        $metadata = self::metadata($alert);

        return array_values(array_filter([
            SourceContextFacts::getString($metadata, SourceContextFacts::SECURITY_CVE),
            SourceContextFacts::getString($metadata, SourceContextFacts::SECURITY_ADVISORY),
        ]));
    }

    /** @return array<string, mixed> */
    private static function metadata(SecurityEvent $alert): array
    {
        $metadata = $alert->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    private static function key(mixed $containerId, string $name, string $version): string
    {
        return implode("\0", [(string) $containerId, mb_strtolower($name), $version]);
    }
}
