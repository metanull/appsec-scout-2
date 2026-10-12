<?php

namespace App\Mcp\Tools\Inventory;

use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-software-systems')]
#[Title('List software systems')]
#[Description('Lists software systems: one per source project (an Azure DevOps project, an AppScan application, a Detectify domain), optionally linked to a software asset. Systems removed upstream are hidden unless include_removed is true. Requires alerts.view.')]
#[IsReadOnly]
final class ListSoftwareSystemsTool extends AppSecTool
{
    public function __construct(private readonly SourceRegistry $sources) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->integer()->min(1)->description('Only systems linked to this software asset.'),
            'source_id' => $schema->string()->enum($this->sourceIds())->description('Only systems from this source.'),
            'search' => Search::schema($schema, 'the system name'),
            'include_removed' => $schema->boolean()->default(false)->description('Include systems no longer reported by their source.'),
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
            'asset_id' => ['nullable', 'integer', 'exists:software_assets,id'],
            'source_id' => ['nullable', 'string', Rule::in($this->sourceIds())],
            'search' => Search::rules(),
            'include_removed' => ['nullable', 'boolean'],
        ], [
            'asset_id.exists' => 'No software asset with id :input.',
        ]);

        $like = Search::like($validated['search'] ?? null);

        $query = SoftwareSystem::query()
            ->with('softwareAsset:id,name')
            ->withCount('containers')
            ->when(isset($validated['asset_id']), fn ($query) => $query->where('software_asset_id', $validated['asset_id']))
            ->when(isset($validated['source_id']), fn ($query) => $query->where('source_id', $validated['source_id']))
            ->when(! ($validated['include_removed'] ?? false), fn ($query) => $query->whereNull('removed_at'))
            ->when($like !== null, fn ($query) => $query->where('name', 'like', $like))
            ->orderBy('name')
            ->orderBy('id');

        return Pagination::paginate($query, $request, fn (SoftwareSystem $system): array => [
            'id' => $system->id,
            'name' => $system->name,
            'source_id' => $system->source_id,
            'source_system_id' => $system->source_system_id,
            'asset' => $system->softwareAsset === null ? null : [
                'id' => $system->softwareAsset->id,
                'name' => $system->softwareAsset->name,
            ],
            'url' => $system->url,
            'containers_count' => (int) $system->getAttribute('containers_count'),
            'removed_at' => Format::date($system->getAttribute('removed_at')),
        ]);
    }

    /** @return list<string> */
    private function sourceIds(): array
    {
        return array_map(fn (Source $source): string => $source->id(), $this->sources->all());
    }
}
