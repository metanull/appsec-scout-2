<?php

namespace App\Mcp\Tools\Inventory;

use App\Mcp\Support\Pagination;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareAsset;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-software-assets')]
#[Title('List software assets')]
#[Description('Lists software assets: the top of the inventory, each grouping one or more software systems from any source. Use an asset id to triage everything it covers at once. Requires alerts.view.')]
#[IsReadOnly]
final class ListSoftwareAssetsTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => Search::schema($schema, 'the asset name'),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(['search' => Search::rules()]);
        $like = Search::like($validated['search'] ?? null);

        $query = SoftwareAsset::query()
            ->withCount('softwareSystems')
            ->when($like !== null, fn ($query) => $query->where('name', 'like', $like))
            ->orderBy('name')
            ->orderBy('id');

        return Pagination::paginate($query, $request, fn (SoftwareAsset $asset): array => [
            'id' => $asset->id,
            'name' => $asset->name,
            'description' => $asset->description === null ? null : Str::limit($asset->description, 200),
            'systems_count' => (int) $asset->getAttribute('software_systems_count'),
        ]);
    }
}
