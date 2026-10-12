<?php

namespace App\Mcp\Tools\Inventory;

use App\Assets\ContainerSystemLinkService;
use App\Mcp\Support\ContainerLinkResult;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityContainer;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('restore-container-source-link')]
#[Title('Restore a container\'s source link')]
#[Description('Moves a manually linked security container (manual_link in list-security-containers) back to the software system its source reports it under, with its alerts, local findings and SBOM components. Defaults to a dry run that describes the change; call again with dry_run=false to apply. Requires context.curate.')]
final class RestoreContainerSourceLinkTool extends AppSecTool
{
    public function __construct(private readonly ContainerSystemLinkService $links) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'container_id' => $schema->integer()->min(1)->required()->description('The security container id (from list-security-containers).'),
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
            ['container_id' => ['required', 'integer', 'exists:security_containers,id']],
            ['container_id.exists' => 'No security container with id :input.'],
        );

        $container = SecurityContainer::query()->findOrFail((int) $validated['container_id']);

        if (FindingSelection::isDryRun($request)) {
            $source = $this->links->resetTarget($container);
            $this->links->ensureMovable($container, $source);

            return Response::structured(ContainerLinkResult::preview($container, $source, 'Nothing changed. Applying moves the container, its alerts, local findings and SBOM components back to its source system.'));
        }

        return Response::structured(ContainerLinkResult::applied($this->links->reset($container, $actor)));
    }
}
