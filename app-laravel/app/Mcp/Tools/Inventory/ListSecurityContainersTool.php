<?php

namespace App\Mcp\Tools\Inventory;

use App\Database\TextSearch;
use App\Mcp\Support\ContainerLinkResult;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-security-containers')]
#[Title('List security containers')]
#[Description('Lists security containers (e.g. repositories, scans) with the software system they belong to; manual_link marks a container an operator moved away from its source_system. Filter by system, or by asset to list the containers of all its systems. Containers removed upstream are hidden unless include_removed is true. Use get-container-links for a container\'s repository and deployment URLs. Requires alerts.view.')]
#[IsReadOnly]
final class ListSecurityContainersTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'system_id' => $schema->integer()->min(1)->description('Only containers of this software system.'),
            'asset_id' => $schema->integer()->min(1)->description('Only containers of the software systems linked to this asset.'),
            'search' => Search::schema($schema, 'the container name'),
            'include_removed' => $schema->boolean()->default(false)->description('Include containers no longer reported by their source.'),
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
            'system_id' => ['nullable', 'integer', 'exists:software_systems,id'],
            'asset_id' => ['nullable', 'integer', 'exists:software_assets,id'],
            'search' => Search::rules(),
            'include_removed' => ['nullable', 'boolean'],
        ], [
            'system_id.exists' => 'No software system with id :input.',
            'asset_id.exists' => 'No software asset with id :input.',
        ]);

        $query = SecurityContainer::query()
            ->with(['softwareSystem:id,name,source_id', 'sourceSoftwareSystem:id,name'])
            ->when(isset($validated['system_id']), fn ($query) => $query->where('software_system_id', $validated['system_id']))
            ->when(isset($validated['asset_id']), fn ($query) => $query->whereIn(
                'software_system_id',
                SoftwareSystem::query()->select('id')->where('software_asset_id', $validated['asset_id']),
            ))
            ->when(! ($validated['include_removed'] ?? false), fn ($query) => $query->whereNull('removed_at'))
            ->orderBy(SoftwareSystem::query()->select('name')->whereColumn('software_systems.id', 'security_containers.software_system_id'))
            ->orderBy('name')
            ->orderBy('id');

        TextSearch::apply($query, ['name'], $validated['search'] ?? null);

        return Pagination::paginate($query, $request, fn (SecurityContainer $container): array => [
            'id' => $container->id,
            'name' => $container->name,
            'kind' => $container->kind,
            'system' => $container->softwareSystem === null ? null : [
                'id' => $container->softwareSystem->id,
                'name' => $container->softwareSystem->name,
                'source_id' => $container->softwareSystem->source_id,
            ],
            ...ContainerLinkResult::linkKeys($container),
            'url' => $container->url,
            'removed_at' => Format::date($container->getAttribute('removed_at')),
        ]);
    }
}
