<?php

namespace App\SourceCode;

use App\Models\Enums\RepositoryProviderType;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SecurityEvents\RepositoryMappingResolver;
use App\Sources\Context\SourceContextFacts;

/**
 * Resolves the RepositoryCodeIdentity used to build code-browse/file links for
 * a container/system pair.
 *
 * An explicit operator RepositoryMapping wins when present (it may point the
 * code at a different provider, a monorepo sub-path, or a manual URL). Absent
 * one, the container's *own* identity is used: a Source (AzDO, GitHub, …), and
 * equally the SBOM/static-analysis import, already records the repository's
 * browse URL (`url`), its provider (`source.provider`) and default branch
 * (`code.default_branch`) on the container when the org is enumerated — so a
 * mapping is an optional override, never a prerequisite for linking.
 */
final class RepositoryCodeIdentityResolver
{
    public function __construct(
        private readonly RepositoryMappingResolver $mappingResolver,
        private readonly RepositoryCodeUrlGenerator $urlGenerator,
    ) {}

    public function resolve(?SecurityContainer $container, ?SoftwareSystem $system): ?RepositoryCodeIdentity
    {
        $mapping = $this->mappingResolver->resolveFromOwners($container, $system);

        if ($mapping !== null) {
            $identity = $this->urlGenerator->identityFromMapping($mapping);

            if ($identity !== null) {
                return $identity;
            }
        }

        return $this->identityFromContainer($container);
    }

    private function identityFromContainer(?SecurityContainer $container): ?RepositoryCodeIdentity
    {
        if (! $container instanceof SecurityContainer) {
            return null;
        }

        $browseUrl = $container->url;

        if (! is_string($browseUrl) || $browseUrl === '') {
            return null;
        }

        $metadata = $container->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        $providerValue = SourceContextFacts::getString($metadata, SourceContextFacts::SOURCE_PROVIDER);
        $providerType = $providerValue !== null ? RepositoryProviderType::tryFrom($providerValue) : null;

        if ($providerType === null) {
            return null;
        }

        return new RepositoryCodeIdentity(
            providerType: $providerType,
            repositoryBrowseUrl: rtrim($browseUrl, '/'),
            defaultBranch: SourceContextFacts::getString($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH),
            pathPrefix: null,
        );
    }
}
