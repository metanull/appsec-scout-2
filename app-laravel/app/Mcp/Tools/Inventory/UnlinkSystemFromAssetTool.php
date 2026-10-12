<?php

namespace App\Mcp\Tools\Inventory;

use App\Assets\SoftwareAssetService;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('unlink-system-from-asset')]
#[Title('Unlink a software system from its asset')]
#[Description('Removes a software system from the software asset it is linked to; the system and its findings stay, just no longer grouped under that asset. Defaults to a dry run that describes the change; call again with dry_run=false to apply. Requires context.curate.')]
final class UnlinkSystemFromAssetTool extends AppSecTool
{
    public function __construct(private readonly SoftwareAssetService $assets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'system_id' => $schema->integer()->min(1)->required()->description('The software system id (from list-software-systems).'),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['context.curate'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(
            ['system_id' => ['required', 'integer', 'exists:software_systems,id']],
            ['system_id.exists' => 'No software system with id :input.'],
        );

        $system = SoftwareSystem::query()->findOrFail((int) $validated['system_id']);

        if ($system->software_asset_id === null) {
            throw new InvalidArgumentException("Software system #{$system->id} is not linked to an asset.");
        }

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying unlinks the system from its asset and re-stamps its local findings and SBOM components.',
                'system_id' => $system->id,
                'previous_asset_id' => $system->software_asset_id,
            ]);
        }

        $change = $this->assets->detach($system, $actor);

        return Response::structured([
            'dry_run' => false,
            'system_id' => $change->system->id,
            'previous_asset_id' => $change->previousAssetId,
            'restamped' => $change->restamped,
        ]);
    }
}
