<?php

namespace App\Mcp\Tools\Sbom;

use App\Assets\AttachmentIngestionService;
use App\Assets\Sbom\ComponentAlertMatcher;
use App\Assets\Sbom\ComponentVulnerabilityMatcher;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\VulnerabilityFilters;
use App\Mcp\Tools\AppSecTool;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-sbom')]
#[Title('Get SBOM')]
#[Description('Returns the SBOM catalog of one security container (SBOMs are collected per repository): its latest SBOM reports (sbom: from the lock files and manifests; sbom_archives: from the JAR/WAR/EAR archives committed to the repository, null when it has none), and its current components from both (paginated, most vulnerable first). Each component has its matching vulnerabilities (local findings of kind "vulnerability" with the same owner and package name + version) and its matching source alerts (e.g. AzDO dependency alerts on the same container, package name ignoring case + version); the same advisory can appear in both. vulnerability_status / vulnerability_severity choose which vulnerabilities and alerts are listed. vulnerable_only and the most-vulnerable-first order use the Trivy vulnerabilities only, since an alert\'s package is in its JSON metadata. Requires alerts.view.')]
#[IsReadOnly]
final class GetSbomTool extends AppSecTool
{
    public function __construct(
        private readonly ComponentVulnerabilityMatcher $matcher,
        private readonly ComponentAlertMatcher $alerts,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'container_id' => $schema->integer()->min(1)->required()->description('The security container id (from list-security-containers).'),
            ...VulnerabilityFilters::schema($schema),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(
            ['container_id' => ['required', 'integer', 'exists:security_containers,id']],
            ['container_id.exists' => 'No security container with id :input.'],
        );

        $filters = VulnerabilityFilters::fromRequest($request);
        $container = SecurityContainer::query()->with('softwareSystem:id,name')->findOrFail((int) $validated['container_id']);

        $query = SoftwareComponent::query()
            ->whereMorphedTo('owner', $container)
            ->whereNull('removed_at');

        if ($filters->vulnerableOnly) {
            $this->matcher->applyHasMatchingVulnerability($query, $filters->statuses, $filters->severities);
        }

        $this->matcher->orderByMostSevereVulnerability($query, $filters->statuses, $filters->severities);
        $query->orderBy('name')->orderBy('id');

        $page = Pagination::page($query, $request);
        $components = collect($page->items());
        $vulnerabilities = $this->matcher->vulnerabilitiesFor($components, $filters->statuses, $filters->severities);
        $alerts = $this->alerts->alertsFor($components, $filters->statuses, $filters->severities);

        $items = $components->map(fn (SoftwareComponent $component): array => [
            'id' => $component->id,
            'name' => $component->name,
            'version' => $component->version,
            'ecosystem' => $component->ecosystem,
            'purl' => $component->purl,
            'license' => $component->license,
            'vulnerabilities' => array_map(fn (LocalFinding $finding): array => [
                'finding_id' => $finding->id,
                'rule_id' => $finding->rule_id,
                'title' => $finding->title,
                'severity' => ComponentVulnerabilityMatcher::bucket($finding),
                'status' => Format::enum($finding->getAttribute('status')),
            ], $vulnerabilities[$component->id] ?? []),
            'alerts' => array_map(fn (SecurityEvent $alert): array => [
                'alert_id' => $alert->id,
                'advisory' => ComponentAlertMatcher::advisoryIds($alert)[0] ?? $alert->rule_id,
                'title' => $alert->title,
                'severity' => ComponentAlertMatcher::bucket($alert),
                'state' => Format::enum($alert->getAttribute('state')),
            ], $alerts[$component->id] ?? []),
        ])->values()->all();

        return Pagination::respond($page, $items, [
            'container' => [
                'id' => $container->id,
                'name' => $container->name,
                'system' => FindingPresenter::named($container->softwareSystem),
            ],
            'sbom' => $this->latestReport($container, AttachmentIngestionService::KIND_SBOM),
            'sbom_archives' => $this->latestReport($container, AttachmentIngestionService::KIND_SBOM_ARCHIVES),
        ]);
    }

    /** @return array{attachment_id: int, name: string, created_at: string|null, size_bytes: int}|null */
    private function latestReport(SecurityContainer $container, string $kind): ?array
    {
        $report = Attachment::query()
            ->whereMorphedTo('owner', $container)
            ->where('kind', $kind)
            ->latest('created_at')
            ->latest('id')
            ->first(['id', 'name', 'created_at', 'size_bytes']);

        return $report === null ? null : [
            'attachment_id' => (int) $report->id,
            'name' => (string) $report->name,
            'created_at' => Format::date($report->getAttribute('created_at')),
            'size_bytes' => (int) $report->size_bytes,
        ];
    }
}
