<?php

namespace App\Mcp\Tools\Sbom;

use App\Assets\AttachmentIngestionService;
use App\Assets\Sbom\ComponentVulnerabilityMatcher;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\VulnerabilityFilters;
use App\Mcp\Tools\AppSecTool;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
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
#[Description('Returns the SBOM catalog of one security container (SBOMs are collected per repository): its latest SBOM report, and its current components (paginated, most vulnerable first), each with the matching vulnerabilities (local findings of kind "vulnerability" with the same owner and package name + version). vulnerability_status / vulnerability_severity choose which vulnerabilities are listed; vulnerable_only keeps only components with at least one. Requires alerts.view.')]
#[IsReadOnly]
final class GetSbomTool extends AppSecTool
{
    public function __construct(private readonly ComponentVulnerabilityMatcher $matcher) {}

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

        $sbom = Attachment::query()
            ->whereMorphedTo('owner', $container)
            ->where('kind', AttachmentIngestionService::KIND_SBOM)
            ->latest('created_at')
            ->latest('id')
            ->first(['id', 'name', 'created_at', 'size_bytes']);

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
        ])->values()->all();

        return Pagination::respond($page, $items, [
            'container' => [
                'id' => $container->id,
                'name' => $container->name,
                'system' => FindingPresenter::named($container->softwareSystem),
            ],
            'sbom' => $sbom === null ? null : [
                'attachment_id' => $sbom->id,
                'name' => $sbom->name,
                'created_at' => Format::date($sbom->getAttribute('created_at')),
                'size_bytes' => $sbom->size_bytes,
            ],
        ]);
    }
}
