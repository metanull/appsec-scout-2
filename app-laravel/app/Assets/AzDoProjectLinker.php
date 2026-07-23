<?php

namespace App\Assets;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\SourceCode\RepositoryCodeIdentityResolver;
use App\SourceCode\RepositoryMappingService;
use App\SourceControl\AzDo\AzDoRepos;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the Azure DevOps organization's projects reflected as SoftwareAsset
 * rows: every AzDO project gets its own SoftwareAsset (unless the underlying
 * SoftwareSystem is already linked to one — manual or automatic assignment is
 * never overwritten).
 *
 * It also backfills a RepositoryMapping for a repository container, but only
 * when the container does not carry its own code identity. An AzDO repository
 * container records its own browse URL, provider and default branch, which the
 * link machinery reads directly; a mapping is created only for a container that
 * lacks that native identity, where it supplies the missing link.
 *
 * Every method is a no-op unless the record actually originates from the
 * 'azdo' source, so it is safe to call unconditionally from any sync path.
 */
final class AzDoProjectLinker
{
    public function __construct(
        private readonly SoftwareAssetService $softwareAssets,
        private readonly RepositoryMappingService $repositoryMappings,
        private readonly AzDoRepos $azdoRepos,
        private readonly RepositoryCodeIdentityResolver $identityResolver,
    ) {}

    public function linkSystemToAsset(SoftwareSystem $system): void
    {
        if ($system->source_id !== AzDoNormalizer::SOURCE_ID || $system->software_asset_id !== null) {
            return;
        }

        $asset = SoftwareAsset::query()->create([
            'name' => $system->name,
            'description' => "Automatically created for the Azure DevOps project '{$system->name}'.",
        ]);

        $this->softwareAssets->attach($asset, $system);
    }

    public function ensureRepositoryMapping(SecurityContainer $container): void
    {
        if ($container->kind !== 'repository') {
            return;
        }

        $system = $container->softwareSystem;

        if (! $system instanceof SoftwareSystem || $system->source_id !== AzDoNormalizer::SOURCE_ID) {
            return;
        }

        if ($container->repositoryMappings()->exists()) {
            return;
        }

        // A container that resolves to its own code identity needs no mapping to
        // build code links.
        if ($this->identityResolver->containerIdentity($container) !== null) {
            return;
        }

        // Repo linking uses the Source Control organization (azdo-repos.*),
        // distinct from the alert-ingestion Source credential (azdo.*).
        $organization = $this->azdoRepos->organization();

        if ($organization === null) {
            return;
        }

        $baseUrl = 'https://dev.azure.com/' . rawurlencode($organization) . '/' . rawurlencode($system->name);

        $provider = RepositoryProvider::query()->firstOrCreate(
            ['provider_type' => RepositoryProviderType::AzureRepos->value, 'base_url' => $baseUrl],
            ['name' => $system->name . ' (Azure Repos)'],
        );

        $metadata = $container->getAttribute('metadata');
        $defaultBranch = SourceContextFacts::getString(is_array($metadata) ? $metadata : [], SourceContextFacts::CODE_DEFAULT_BRANCH);

        try {
            $this->repositoryMappings->create($container, null, [
                'repository_provider_id' => $provider->id,
                'repository_name' => $container->name,
                'default_branch' => $defaultBranch ?? 'main',
            ]);
        } catch (ValidationException) {
            // Best-effort: an unsafe/duplicate derived mapping should not break the sync.
        }
    }
}
