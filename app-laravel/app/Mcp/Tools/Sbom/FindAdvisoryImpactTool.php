<?php

namespace App\Mcp\Tools\Sbom;

use App\Assets\Sbom\ComponentAlertMatcher;
use App\Database\TextSearch;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Scope;
use App\Mcp\Support\SeverityRank;
use App\Mcp\Support\VulnerabilityFilters;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find-advisory-impact')]
#[Title('Find advisory impact')]
#[Description('Finds what one advisory affects, given its id (a CVE such as CVE-2021-44228, or a GHSA id), across the whole inventory or within an asset, system or container. It gathers the local findings of kind "vulnerability" (Trivy) whose rule id is the advisory, and the source alerts that name it as their CVE or advisory id (e.g. AzDO dependency alerts, AppScan open-source alerts). Results are grouped by affected package and version (names compared ignoring case; package null for alerts that name no package), then by owner: the container, or the system for an alert reported against a whole system. Each owner has its system and asset and the status and severity of every finding and alert; a finding correlated to an alert gives its id in correlated_alert_id. The summary counts the packages, containers, systems and assets affected. vulnerability_status / vulnerability_severity choose which findings and alerts count. The result is not paginated, since one advisory affects a bounded set of packages. Requires alerts.view.')]
#[IsReadOnly]
final class FindAdvisoryImpactTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'advisory' => $schema->string()->max(64)->required()
                ->description('The advisory id, ignoring case: a CVE (CVE-2021-44228) or a GHSA id (GHSA-jfh8-c2jp-5v3q).'),
            ...VulnerabilityFilters::countingSchema($schema),
            ...Scope::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        // Letters, digits and dashes only: the id is then also a LIKE pattern without wildcards.
        $validated = $request->validate(
            ['advisory' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z]+-[A-Za-z0-9-]+$/']],
            ['advisory.regex' => 'The advisory must be an id such as CVE-2021-44228 or GHSA-jfh8-c2jp-5v3q.'],
        );

        $advisory = (string) $validated['advisory'];
        $filters = VulnerabilityFilters::fromRequest($request);
        $scope = Scope::fromRequest($request);

        $findings = $this->findings($advisory, $filters, $scope);
        $alerts = $this->alerts($advisory, $filters, $scope);
        $ecosystems = $this->findingEcosystems($findings);

        /** @var array<string, array{package: array<string, mixed>|null, owners: array<string, array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($findings as $finding) {
            $package = $finding->package_name === null ? null : [
                'name' => $finding->package_name,
                'version' => $finding->package_version,
                'ecosystem' => $ecosystems[self::componentKey($finding->owner_type, $finding->owner_id, $finding->package_name, $finding->package_version)] ?? null,
            ];

            $this->add($groups, $package, $finding->owner, $finding->softwareSystem, $finding->softwareAsset, 'findings', [
                'id' => $finding->id,
                'status' => Format::enum($finding->getAttribute('status')),
                'severity' => SeverityRank::ofFinding($finding),
                'file_path' => $finding->file_path,
                'correlated_alert_id' => $finding->correlated_security_event_id,
            ]);
        }

        foreach ($alerts as $alert) {
            $this->add($groups, ComponentAlertMatcher::package($alert), $alert->container ?? $alert->softwareSystem, $alert->softwareSystem, $alert->softwareSystem?->softwareAsset, 'alerts', [
                'id' => $alert->id,
                'source_id' => $alert->source_id,
                'type' => Format::enum($alert->getAttribute('type')),
                'state' => Format::enum($alert->getAttribute('state')),
                'severity' => SeverityRank::ofAlert($alert),
                'title' => $alert->title,
            ]);
        }

        $packages = array_map(fn (array $group): array => $this->renderGroup($group), array_values($groups));

        usort($packages, fn (array $left, array $right): int => [SeverityRank::rank((string) $right['max_severity']), $left['package']['name'] ?? '', $left['package']['version'] ?? '']
            <=> [SeverityRank::rank((string) $left['max_severity']), $right['package']['name'] ?? '', $right['package']['version'] ?? '']);

        $owners = collect($packages)->flatMap(fn (array $package): array => $package['owners']);

        return Response::structured([
            'advisory' => $advisory,
            'title' => $findings->pluck('title')->merge($alerts->pluck('title'))->first(),
            'summary' => [
                'packages_count' => count($packages),
                'containers_count' => $owners->filter(fn (array $owner): bool => ($owner['owner']['type'] ?? null) === 'container')->pluck('owner.id')->unique()->count(),
                'systems_count' => $owners->pluck('system.id')->filter()->unique()->count(),
                'assets_count' => $owners->pluck('asset.id')->filter()->unique()->count(),
                'findings_count' => $findings->count(),
                'alerts_count' => $alerts->count(),
                'max_severity' => SeverityRank::highest(array_column($packages, 'max_severity')),
            ],
            'packages' => $packages,
        ]);
    }

    /** @return Collection<int, LocalFinding> */
    private function findings(string $advisory, VulnerabilityFilters $filters, Scope $scope): Collection
    {
        $query = LocalFinding::query()
            ->with(['owner', 'softwareSystem:id,name', 'softwareAsset:id,name'])
            ->where('kind', LocalFinding::KIND_VULNERABILITY)
            ->whereLike('rule_id', $advisory);

        LocalFindingTableQuery::applyStatuses($query, $filters->statuses);
        LocalFindingTableQuery::applyEffectiveSeverities($query, $filters->severities);
        LocalFindingTableQuery::applyAssetScopes($query, $scope->assetIds());
        LocalFindingTableQuery::applySystemScopes($query, $scope->systemIds());
        LocalFindingTableQuery::applyContainerScopes($query, $scope->containerIds());

        return $query->orderBy('id')->get();
    }

    /**
     * Alerts whose CVE or advisory fact is the advisory. The facts live in JSON metadata, so the
     * query narrows the candidates with a text search and the exact match runs here.
     *
     * @return Collection<int, SecurityEvent>
     */
    private function alerts(string $advisory, VulnerabilityFilters $filters, Scope $scope): Collection
    {
        $query = SecurityEvent::query()
            ->with(['container:id,name', 'softwareSystem:id,name,software_asset_id', 'softwareSystem.softwareAsset:id,name']);

        TextSearch::apply($query, ['rule_id', 'metadata'], $advisory, ['metadata']);
        SecurityEventTableQuery::applyStates($query, $filters->statuses);
        SecurityEventTableQuery::applySeverities($query, $filters->severities);
        SecurityEventTableQuery::applyAssetScopes($query, $scope->assetIds());
        SecurityEventTableQuery::applySystemScopes($query, $scope->systemIds());
        SecurityEventTableQuery::applyContainerScopes($query, $scope->containerIds());

        return $query->orderBy('id')->get()
            ->filter(fn (SecurityEvent $alert): bool => in_array(
                strtolower($advisory),
                array_map(strtolower(...), ComponentAlertMatcher::advisoryIds($alert)),
                true,
            ))
            ->values();
    }

    /**
     * The ecosystem of each finding's package, from the SBOM component on the same owner, package
     * name and version (local findings do not record it).
     *
     * @param  Collection<int, LocalFinding>  $findings
     * @return array<string, string|null>
     */
    private function findingEcosystems(Collection $findings): array
    {
        $named = $findings->filter(fn (LocalFinding $finding): bool => $finding->package_name !== null);

        if ($named->isEmpty()) {
            return [];
        }

        return SoftwareComponent::query()
            ->whereIn('owner_type', $named->pluck('owner_type')->unique()->values()->all())
            ->whereIn('owner_id', $named->pluck('owner_id')->unique()->values()->all())
            ->whereIn('name', $named->pluck('package_name')->unique()->values()->all())
            ->get(['owner_type', 'owner_id', 'name', 'version', 'ecosystem'])
            ->mapWithKeys(fn (SoftwareComponent $component): array => [
                self::componentKey($component->owner_type, $component->owner_id, $component->name, $component->version) => $component->ecosystem,
            ])
            ->all();
    }

    /**
     * @param  array<string, array{package: array<string, mixed>|null, owners: array<string, array<string, mixed>>}>  $groups
     * @param  array<string, mixed>|null  $package
     * @param  'findings'|'alerts'  $list
     * @param  array<string, mixed>  $row
     */
    private function add(array &$groups, ?array $package, ?Model $owner, ?Model $system, ?Model $asset, string $list, array $row): void
    {
        $groupKey = $package === null ? '' : mb_strtolower((string) $package['name']) . "\0" . (string) $package['version'];
        $ownerKey = $owner === null ? '' : $owner::class . "\0" . $owner->getKey();

        $groups[$groupKey] ??= ['package' => $package, 'owners' => []];

        // A Trivy finding and an alert can report the same package; keep the first ecosystem known.
        if ($package !== null && $groups[$groupKey]['package'] !== null) {
            $groups[$groupKey]['package']['ecosystem'] ??= $package['ecosystem'] ?? null;
        }

        $groups[$groupKey]['owners'][$ownerKey] ??= [
            'owner' => FindingPresenter::owner($owner),
            'system' => FindingPresenter::named($system),
            'asset' => FindingPresenter::named($asset),
            'findings' => [],
            'alerts' => [],
        ];
        $groups[$groupKey]['owners'][$ownerKey][$list][] = $row;
    }

    /**
     * @param  array{package: array<string, mixed>|null, owners: array<string, array<string, mixed>>}  $group
     * @return array<string, mixed>
     */
    private function renderGroup(array $group): array
    {
        $owners = array_values($group['owners']);

        usort($owners, fn (array $left, array $right): int => [$left['system']['name'] ?? '', $left['owner']['name'] ?? '']
            <=> [$right['system']['name'] ?? '', $right['owner']['name'] ?? '']);

        $severities = collect($owners)->flatMap(fn (array $owner): array => [...$owner['findings'], ...$owner['alerts']])->pluck('severity');

        return [
            'package' => $group['package'],
            'max_severity' => SeverityRank::highest($severities),
            'owners' => $owners,
        ];
    }

    private static function componentKey(mixed $ownerType, mixed $ownerId, mixed $name, mixed $version): string
    {
        return implode("\0", [(string) $ownerType, (string) $ownerId, mb_strtolower((string) $name), (string) $version]);
    }
}
