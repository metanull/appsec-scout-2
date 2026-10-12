<?php

namespace App\Mcp\Tools\Inventory;

use App\Assets\ContainerSystemLinkService;
use App\Mcp\Support\ContainerLinkResult;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('move-container-to-system')]
#[Title('Move a container to another software system')]
#[Description('Links a security container (e.g. a repository) to another software system than the one its source reports, e.g. when the repository belongs to a different project. Its alerts, local findings and SBOM components move with it, and later fetches keep the new link until restore-container-source-link. Defaults to a dry run that describes the change; call again with dry_run=false to apply. Requires context.curate.')]
final class MoveContainerToSystemTool extends AppSecTool
{
    public function __construct(private readonly ContainerSystemLinkService $links) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'container_id' => $schema->integer()->min(1)->required()->description('The security container id (from list-security-containers).'),
            'system_id' => $schema->integer()->min(1)->required()->description('The software system to link it to (from list-software-systems).'),
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
            'container_id' => ['required', 'integer', 'exists:security_containers,id'],
            'system_id' => ['required', 'integer', 'exists:software_systems,id'],
        ], [
            'container_id.exists' => 'No security container with id :input.',
            'system_id.exists' => 'No software system with id :input.',
        ]);

        $container = SecurityContainer::query()->findOrFail((int) $validated['container_id']);
        $target = SoftwareSystem::query()->findOrFail((int) $validated['system_id']);

        if (FindingSelection::isDryRun($request)) {
            $this->links->ensureMovable($container, $target);

            return Response::structured(ContainerLinkResult::preview($container, $target, 'Nothing changed. Applying moves the container, its alerts, local findings and SBOM components to the system.'));
        }

        return Response::structured(ContainerLinkResult::applied($this->links->relink($container, $target, $actor)));
    }
}
