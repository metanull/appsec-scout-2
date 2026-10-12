<?php

namespace App\Mcp\Tools\Inventory;

use App\Mcp\Support\ContainerLinkResult;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\User;
use App\SecurityEvents\EntityNavigationCatalog;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-container-links')]
#[Title('Get container links')]
#[Description('Returns where a security container lives: its browse URL, git clone URL and default branch, its software system (whose url is the scanned, deployed site for web scanners such as AppScan and Detectify), repository mappings, and every curated or tracker link known for the container and its system. A deployed-instance URL, when known, is the system url or a curated link. Requires alerts.view.')]
#[IsReadOnly]
final class GetContainerLinksTool extends AppSecTool
{
    private const LINK_RELATIONS = ['curatedLinks', 'trackerProjectLinks', 'repositoryMappings.repositoryProvider'];

    public function __construct(private readonly EntityNavigationCatalog $catalog) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'container_id' => $schema->integer()->min(1)->required()->description('The security container id (from list-security-containers).'),
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

        $container = SecurityContainer::query()
            ->with([
                'sourceSoftwareSystem:id,name',
                ...self::LINK_RELATIONS,
                ...array_map(fn (string $relation): string => 'softwareSystem.' . $relation, self::LINK_RELATIONS),
            ])
            ->findOrFail((int) $validated['container_id']);

        $system = $container->softwareSystem;
        $metadata = Format::arrayAttribute($container->getAttribute('metadata'));

        $mappings = $container->repositoryMappings
            ->map(fn (RepositoryMapping $mapping): array => self::mapping($mapping, 'container'))
            ->concat($system?->repositoryMappings->map(fn (RepositoryMapping $mapping): array => self::mapping($mapping, 'system')) ?? []);

        return Response::structured([
            'container' => [
                'id' => $container->id,
                'name' => $container->name,
                'kind' => $container->kind,
                ...ContainerLinkResult::linkKeys($container),
                'url' => $container->url,
                'clone_url' => SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL),
                'default_branch' => SourceContextFacts::getString($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH),
            ],
            'system' => $system === null ? null : [
                'id' => $system->id,
                'name' => $system->name,
                'source_id' => $system->source_id,
                'url' => $system->url,
            ],
            'repository_mappings' => $mappings->values()->all(),
            'links' => [
                ...array_map(fn (array $link): array => ['of' => 'container', ...$link], $this->catalog->buildForSecurityContainer($container)),
                ...array_map(fn (array $link): array => ['of' => 'system', ...$link], $system === null ? [] : $this->catalog->buildForSoftwareSystem($system)),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function mapping(RepositoryMapping $mapping, string $owner): array
    {
        return [
            'of' => $owner,
            'provider' => $mapping->repositoryProvider?->name,
            'repository_name' => $mapping->repository_name,
            'repository_url' => $mapping->repository_url,
            'default_branch' => $mapping->default_branch,
            'path_prefix' => $mapping->path_prefix,
        ];
    }
}
