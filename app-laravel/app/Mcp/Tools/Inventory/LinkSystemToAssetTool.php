<?php

namespace App\Mcp\Tools\Inventory;

use App\Assets\SoftwareAssetService;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('link-system-to-asset')]
#[Title('Link a software system to an asset')]
#[Description('Links a software system to a software asset. A system belongs to at most one asset, so a system already linked elsewhere moves. The local findings and SBOM components under the system follow it at once. Defaults to a dry run that describes the change; call again with dry_run=false to apply. Requires context.curate.')]
#[IsIdempotent]
final class LinkSystemToAssetTool extends AppSecTool
{
    public function __construct(private readonly SoftwareAssetService $assets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'system_id' => $schema->integer()->min(1)->required()->description('The software system id (from list-software-systems).'),
            'asset_id' => $schema->integer()->min(1)->required()->description('The software asset id (from list-software-assets).'),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['context.curate'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'system_id' => ['required', 'integer', 'exists:software_systems,id'],
            'asset_id' => ['required', 'integer', 'exists:software_assets,id'],
        ], [
            'system_id.exists' => 'No software system with id :input.',
            'asset_id.exists' => 'No software asset with id :input.',
        ]);

        $system = SoftwareSystem::query()->findOrFail((int) $validated['system_id']);
        $asset = SoftwareAsset::query()->findOrFail((int) $validated['asset_id']);
        $previousAssetId = $system->software_asset_id;

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying links the system to the asset and re-stamps its local findings and SBOM components.',
                'system_id' => $system->id,
                'asset_id' => $asset->id,
                'previous_asset_id' => $previousAssetId,
            ]);
        }

        $change = $this->assets->attach($asset, $system, $actor);

        return Response::structured([
            'dry_run' => false,
            'system_id' => $change->system->id,
            'asset_id' => $change->system->software_asset_id,
            'previous_asset_id' => $change->previousAssetId,
            'restamped' => $change->restamped,
        ]);
    }
}
