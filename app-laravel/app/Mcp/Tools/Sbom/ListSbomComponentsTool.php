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
use App\Mcp\Support\VulnerabilityFilters;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityContainer;
use App\Models\SoftwareComponent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-sbom-components')]
#[Title('List SBOM components')]
#[Description('Lists the software components (packages) from collected SBOMs for an asset, system or container, each with a summary of its vulnerabilities and of its source alerts, most vulnerable first. Components have no state or severity of their own. Their vulnerabilities are local findings of kind "vulnerability" from the Trivy scan of the same repository, matched on the same owner and package name + version (the ecosystem is not part of the match). Their alerts are source alerts on the same container (e.g. AzDO dependency alerts), matched on package name (ignoring case) + version; the same advisory can appear in both. vulnerability_status / vulnerability_severity choose which vulnerabilities and alerts count. vulnerable_only and the most-vulnerable-first order use the Trivy vulnerabilities only, since an alert\'s package is in its JSON metadata. Components removed from the latest SBOM are hidden unless include_removed. Requires alerts.view.')]
#[IsReadOnly]
final class ListSbomComponentsTool extends AppSecTool
{
    public function __construct(
        private readonly ComponentVulnerabilityMatcher $matcher,
        private readonly ComponentAlertMatcher $alerts,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => Search::schema($schema, 'component name and package URL (purl)'),
            'ecosystem' => $schema->string()->max(64)->description('Only components of this ecosystem (e.g. npm, maven, nuget, pypi).'),
            'include_removed' => $schema->boolean()->default(false)->description('Include components no longer in the latest SBOM.'),
            ...VulnerabilityFilters::schema($schema),
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
            'search' => Search::rules(),
            'ecosystem' => ['nullable', 'string', 'max:64'],
            'include_removed' => ['nullable', 'boolean'],
        ]);

        $filters = VulnerabilityFilters::fromRequest($request);
        $scope = Scope::fromRequest($request);

        $query = SoftwareComponent::query()
            ->with(['owner', 'softwareSystem:id,name'])
            ->when($scope->asset !== null, fn (Builder $query) => $query->where('software_asset_id', $scope->asset?->id))
            ->when($scope->system !== null, fn (Builder $query) => $query->where('software_system_id', $scope->system?->id))
            ->when($scope->container !== null, fn (Builder $query) => $query
                ->where('owner_type', SecurityContainer::class)
                ->where('owner_id', $scope->container?->id))
            ->when(isset($validated['ecosystem']), fn (Builder $query) => $query->where('ecosystem', $validated['ecosystem']))
            ->when(! ($validated['include_removed'] ?? false), fn (Builder $query) => $query->whereNull('removed_at'));

        TextSearch::apply($query, ['name', 'purl'], $validated['search'] ?? null);

        if ($filters->vulnerableOnly) {
            $this->matcher->applyHasMatchingVulnerability($query, $filters->statuses, $filters->severities);
        }

        $this->matcher->orderByMostSevereVulnerability($query, $filters->statuses, $filters->severities);
        $query->orderBy('name')->orderBy('id');

        $page = Pagination::page($query, $request);
        $components = collect($page->items());
        $vulnerabilities = $this->matcher->vulnerabilitiesFor($components, $filters->statuses, $filters->severities);
        $alerts = $this->alerts->alertsFor($components, $filters->statuses, $filters->severities);

        return Pagination::respond($page, $components->map(fn (SoftwareComponent $component): array => [
            ...self::row($component),
            'vulnerabilities' => $this->matcher->summarize($vulnerabilities[$component->id] ?? []),
            'alerts' => $this->alerts->summarize($alerts[$component->id] ?? []),
        ])->values()->all());
    }

    /** @return array<string, mixed> */
    public static function row(SoftwareComponent $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'version' => $component->version,
            'ecosystem' => $component->ecosystem,
            'purl' => $component->purl,
            'license' => $component->license,
            'owner' => FindingPresenter::owner($component->owner),
            'system' => FindingPresenter::named($component->softwareSystem),
            'removed_at' => Format::date($component->getAttribute('removed_at')),
        ];
    }
}
