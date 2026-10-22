<?php

namespace App\Mcp\Tools\Sbom;

use App\Assets\Sbom\ComponentAlertMatcher;
use App\Assets\Sbom\ComponentVulnerabilityMatcher;
use App\Database\TextSearch;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Scope;
use App\Mcp\Support\Search;
use App\Mcp\Support\SeverityRank;
use App\Mcp\Support\VulnerabilityFilters;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find-package-usage')]
#[Title('Find package usage')]
#[Description('Finds where a package is used, across the whole inventory or within an asset, system or container. Every package whose name or package URL (purl) contains `package` (ignoring case) is listed, paginated by package (ecosystem + name), with its versions (natural order, highest first; there is no version-range filter, so compare the listed versions) and, for each version, the containers that use it with their system and asset. Each version lists its advisories (the CVE, else the GHSA id) with their highest severity and how many local findings and source alerts report them: local findings of kind "vulnerability" from the Trivy scan of the same container, matched on package name + version; and source alerts such as AzDO dependency alerts on the same container, matched on package name (ignoring case) + version. Alerts reported against a whole system (e.g. AppScan open-source alerts) have no container and are not listed; find-advisory-impact covers them. Each container lists the advisories that affect it. vulnerability_status / vulnerability_severity choose which findings and alerts count. Components removed from the latest SBOM are left out unless include_removed. Requires alerts.view.')]
#[IsReadOnly]
final class FindPackageUsageTool extends AppSecTool
{
    public function __construct(
        private readonly ComponentVulnerabilityMatcher $findings,
        private readonly ComponentAlertMatcher $alerts,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->min(2)->max(Search::MAX_LENGTH)->required()
                ->description('Substring of the package name or purl, ignoring case (e.g. "log4j-core", "pkg:nuget/Newtonsoft.Json").'),
            'ecosystem' => $schema->string()->max(64)->description('Only packages of this ecosystem (e.g. npm, maven, nuget, pypi).'),
            'include_removed' => $schema->boolean()->default(false)->description('Include components no longer in the latest SBOM.'),
            ...VulnerabilityFilters::countingSchema($schema),
            ...Scope::schema($schema),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'package' => ['required', 'string', 'min:2', 'max:' . Search::MAX_LENGTH],
            'ecosystem' => ['nullable', 'string', 'max:64'],
            'include_removed' => ['nullable', 'boolean'],
        ]);

        $filters = VulnerabilityFilters::fromRequest($request);
        $scope = Scope::fromRequest($request);

        $packages = $this->components($scope, $validated)
            ->select(['ecosystem', 'name'])
            ->groupBy('ecosystem', 'name')
            ->orderBy('name')
            ->orderBy('ecosystem');

        $page = Pagination::page($packages, $request);
        $wanted = collect($page->items())->mapWithKeys(fn (SoftwareComponent $package): array => [self::packageKey($package) => true]);

        $components = $this->components($scope, $validated)
            ->with(['owner', 'softwareSystem:id,name', 'softwareAsset:id,name'])
            ->whereIn('name', collect($page->items())->pluck('name')->unique()->values()->all())
            ->orderBy('id')
            ->get()
            ->filter(fn (SoftwareComponent $component): bool => $wanted->has(self::packageKey($component)))
            ->values();

        $findings = $this->findings->vulnerabilitiesFor($components, $filters->statuses, $filters->severities);
        $alerts = $this->alerts->alertsFor($components, $filters->statuses, $filters->severities);
        $byPackage = $components->groupBy(fn (SoftwareComponent $component): string => self::packageKey($component));

        return Pagination::respond($page, collect($page->items())
            ->map(fn (SoftwareComponent $package): array => $this->package($byPackage->get(self::packageKey($package), collect()), $findings, $alerts))
            ->all());
    }

    /**
     * The scoped, searched components the page of packages and their usages both come from.
     *
     * @param  array<string, mixed>  $validated
     * @return Builder<SoftwareComponent>
     */
    private function components(Scope $scope, array $validated): Builder
    {
        $query = SoftwareComponent::query()
            ->when($scope->asset !== null, fn (Builder $query) => $query->where('software_asset_id', $scope->asset?->id))
            ->when($scope->system !== null, fn (Builder $query) => $query->where('software_system_id', $scope->system?->id))
            ->when($scope->container !== null, fn (Builder $query) => $query
                ->where('owner_type', SecurityContainer::class)
                ->where('owner_id', $scope->container?->id))
            ->when(isset($validated['ecosystem']), fn (Builder $query) => $query->where('ecosystem', $validated['ecosystem']))
            ->when(! ($validated['include_removed'] ?? false), fn (Builder $query) => $query->whereNull('removed_at'));

        return TextSearch::apply($query, ['name', 'purl'], (string) $validated['package']);
    }

    /**
     * @param  Collection<int, SoftwareComponent>  $components  every usage of one package
     * @param  array<int, list<LocalFinding>>  $findings
     * @param  array<int, list<SecurityEvent>>  $alerts
     * @return array<string, mixed>
     */
    private function package(Collection $components, array $findings, array $alerts): array
    {
        $first = $components->first();

        $versions = $components
            ->groupBy(fn (SoftwareComponent $component): string => (string) $component->version)
            ->sortKeysUsing(fn (string $left, string $right): int => strnatcasecmp($right, $left))
            ->map(fn (Collection $usages, string $version): array => $this->version($version, $usages, $findings, $alerts))
            ->values()
            ->all();

        return [
            'ecosystem' => $first?->ecosystem,
            'name' => $first?->name,
            'containers_count' => $components->map(fn (SoftwareComponent $component): string => self::ownerKey($component))->unique()->count(),
            'max_severity' => SeverityRank::highest(array_column($versions, 'max_severity')),
            'versions' => $versions,
        ];
    }

    /**
     * @param  Collection<int, SoftwareComponent>  $usages  the components of one package version
     * @param  array<int, list<LocalFinding>>  $findings
     * @param  array<int, list<SecurityEvent>>  $alerts
     * @return array<string, mixed>
     */
    private function version(string $version, Collection $usages, array $findings, array $alerts): array
    {
        /** @var array<string, array{severities: list<string>, findings: array<int, true>, alerts: array<int, true>}> $advisories */
        $advisories = [];
        /** @var array<string, array<string, mixed>> $owners */
        $owners = [];

        foreach ($usages as $component) {
            $owner = $owners[self::ownerKey($component)] ?? [
                'owner' => FindingPresenter::owner($component->owner),
                'system' => FindingPresenter::named($component->softwareSystem),
                'asset' => FindingPresenter::named($component->softwareAsset),
                'removed_at' => Format::date($component->getAttribute('removed_at')),
                'advisories' => [],
            ];

            foreach ($findings[$component->id] ?? [] as $finding) {
                $id = (string) $finding->rule_id;
                $advisories[$id]['severities'][] = SeverityRank::ofFinding($finding);
                $advisories[$id]['findings'][(int) $finding->id] = true;
                $owner['advisories'][$id] = true;
            }

            foreach ($alerts[$component->id] ?? [] as $alert) {
                $id = ComponentAlertMatcher::advisoryIds($alert)[0] ?? (string) $alert->rule_id;
                $advisories[$id]['severities'][] = SeverityRank::ofAlert($alert);
                $advisories[$id]['alerts'][(int) $alert->id] = true;
                $owner['advisories'][$id] = true;
            }

            $owners[self::ownerKey($component)] = $owner;
        }

        $advisoryRows = collect($advisories)
            ->map(fn (array $advisory, string $id): array => [
                'id' => $id,
                'severity' => SeverityRank::highest($advisory['severities']),
                'findings_count' => count($advisory['findings'] ?? []),
                'alerts_count' => count($advisory['alerts'] ?? []),
            ])
            ->sortBy([
                fn (array $left, array $right): int => SeverityRank::rank((string) $right['severity']) <=> SeverityRank::rank((string) $left['severity']),
                fn (array $left, array $right): int => strcmp($left['id'], $right['id']),
            ])
            ->values()
            ->all();

        $containers = collect($owners)
            ->map(fn (array $owner): array => [...$owner, 'advisories' => array_keys($owner['advisories'])])
            ->sortBy([
                fn (array $left, array $right): int => strcmp((string) ($left['system']['name'] ?? ''), (string) ($right['system']['name'] ?? '')),
                fn (array $left, array $right): int => strcmp((string) ($left['owner']['name'] ?? ''), (string) ($right['owner']['name'] ?? '')),
            ])
            ->values();

        return [
            'version' => $version === '' ? null : $version,
            'containers_count' => $containers->count(),
            'systems_count' => $containers->pluck('system.id')->filter()->unique()->count(),
            'assets_count' => $containers->pluck('asset.id')->filter()->unique()->count(),
            'max_severity' => SeverityRank::highest(array_column($advisoryRows, 'severity')),
            'advisories' => $advisoryRows,
            'containers' => $containers->all(),
        ];
    }

    private static function packageKey(SoftwareComponent $component): string
    {
        return (string) $component->ecosystem . "\0" . (string) $component->name;
    }

    private static function ownerKey(SoftwareComponent $component): string
    {
        return (string) $component->owner_type . "\0" . (string) $component->owner_id;
    }
}
