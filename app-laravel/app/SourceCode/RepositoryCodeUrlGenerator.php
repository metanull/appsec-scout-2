<?php

namespace App\SourceCode;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;

final class RepositoryCodeUrlGenerator
{
    /**
     * The shared repository browse-URL formatter — the single place that maps a
     * provider type + base URL + already-encoded repository name to the repo root
     * URL. Callers that persist or preview the URL (RepositoryMappingService,
     * RepositoryMappingsRelationManager) funnel through here so every provider
     * type is handled in exactly one place.
     */
    public static function browseUrl(RepositoryProviderType $providerType, string $baseUrl, string $encodedRepositoryName): string
    {
        return match ($providerType) {
            RepositoryProviderType::AzureRepos => $baseUrl . '/_git/' . $encodedRepositoryName,
            RepositoryProviderType::GitHub, RepositoryProviderType::Bitbucket => $baseUrl . '/' . $encodedRepositoryName,
        };
    }

    // ---------------------------------------------------------------------
    // Identity-based API (the single source of truth for URL formatting).
    // ---------------------------------------------------------------------

    public function repositoryUrlFor(RepositoryCodeIdentity $identity): string
    {
        return $identity->repositoryBrowseUrl;
    }

    public function fileUrlFor(RepositoryCodeIdentity $identity, CodeLocation $location): ?string
    {
        $fullPath = $this->joinPaths($identity->pathPrefix, $location->filePath);
        $ref = $this->resolveRef($identity->defaultBranch, $location->branch, $location->commitSha);

        if ($fullPath === null || $ref === null) {
            return null;
        }

        $browseUrl = $identity->repositoryBrowseUrl;

        $url = match ($identity->providerType) {
            RepositoryProviderType::GitHub => $browseUrl . '/blob/' . rawurlencode($ref) . '/' . $fullPath,
            RepositoryProviderType::AzureRepos => $browseUrl . '?path=/' . $fullPath . '&version=' . rawurlencode($this->azureVersionRef($location->commitSha, $ref)),
            RepositoryProviderType::Bitbucket => $browseUrl . '/src/' . rawurlencode($ref) . '/' . $fullPath,
        };

        if ($location->startLine === null) {
            return $url;
        }

        return match ($identity->providerType) {
            RepositoryProviderType::GitHub => $url . '#L' . $location->startLine . ($location->endLine !== null && $location->endLine !== $location->startLine ? '-L' . $location->endLine : ''),
            RepositoryProviderType::AzureRepos => $url
                . '&line=' . $location->startLine
                . ($location->endLine !== null && $location->endLine !== $location->startLine ? '&lineEnd=' . $location->endLine : '')
                . '&lineStartColumn=1&lineEndColumn=1',
            RepositoryProviderType::Bitbucket => $url . '#lines-' . $location->startLine . ($location->endLine !== null && $location->endLine !== $location->startLine ? ':' . $location->endLine : ''),
        };
    }

    public function commitUrlFor(RepositoryCodeIdentity $identity, CodeLocation $location): ?string
    {
        if ($location->commitSha === null || $location->commitSha === '') {
            return null;
        }

        return match ($identity->providerType) {
            RepositoryProviderType::GitHub => $identity->repositoryBrowseUrl . '/commit/' . rawurlencode($location->commitSha),
            RepositoryProviderType::AzureRepos => $identity->repositoryBrowseUrl . '/commit/' . rawurlencode($location->commitSha),
            RepositoryProviderType::Bitbucket => $identity->repositoryBrowseUrl . '/commits/' . rawurlencode($location->commitSha),
        };
    }

    // ---------------------------------------------------------------------
    // RepositoryMapping adapters — an operator override expressed as a
    // provider + repository name (+ optional monorepo prefix). Kept so the
    // mapping stays a first-class way to describe a repository, but they now
    // funnel through the identity API above.
    // ---------------------------------------------------------------------

    public function identityFromMapping(RepositoryMapping $mapping): ?RepositoryCodeIdentity
    {
        $provider = $mapping->repositoryProvider;

        if (! $provider instanceof RepositoryProvider) {
            return null;
        }

        $providerType = $this->providerType($provider);
        $baseUrl = $this->baseUrl($provider);
        $repositoryName = $this->normalizeRelativePath($mapping->repository_name);

        if ($providerType === null || $baseUrl === null || $repositoryName === null) {
            return null;
        }

        $browseUrl = self::browseUrl($providerType, $baseUrl, $repositoryName);

        return new RepositoryCodeIdentity(
            providerType: $providerType,
            repositoryBrowseUrl: $browseUrl,
            defaultBranch: is_string($mapping->default_branch) ? $mapping->default_branch : null,
            pathPrefix: is_string($mapping->path_prefix) ? $mapping->path_prefix : null,
        );
    }

    public function repositoryUrl(RepositoryMapping $mapping): ?string
    {
        $identity = $this->identityFromMapping($mapping);

        return $identity !== null ? $this->repositoryUrlFor($identity) : null;
    }

    public function fileUrl(RepositoryMapping $mapping, CodeLocation $location): ?string
    {
        $identity = $this->identityFromMapping($mapping);

        return $identity !== null ? $this->fileUrlFor($identity, $location) : null;
    }

    public function commitUrl(RepositoryMapping $mapping, CodeLocation $location): ?string
    {
        $identity = $this->identityFromMapping($mapping);

        return $identity !== null ? $this->commitUrlFor($identity, $location) : null;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function providerType(RepositoryProvider $provider): ?RepositoryProviderType
    {
        $providerType = $provider->getRawOriginal('provider_type');

        if ($providerType instanceof RepositoryProviderType) {
            return $providerType;
        }

        if (! is_string($providerType)) {
            return null;
        }

        return RepositoryProviderType::tryFrom($providerType);
    }

    private function baseUrl(RepositoryProvider $provider): ?string
    {
        $baseUrl = $provider->getRawOriginal('base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/');
    }

    private function resolveRef(?string $defaultBranch, ?string $branch, ?string $commitSha): ?string
    {
        if (is_string($commitSha) && $commitSha !== '') {
            return $commitSha;
        }

        if (is_string($branch) && $branch !== '') {
            return $branch;
        }

        if (is_string($defaultBranch) && $defaultBranch !== '') {
            return $defaultBranch;
        }

        return null;
    }

    private function azureVersionRef(?string $commitSha, string $ref): string
    {
        return $commitSha !== null && $commitSha !== '' ? 'GC' . $commitSha : 'GB' . $ref;
    }

    private function joinPaths(?string $prefix, ?string $path): ?string
    {
        $normalizedPrefix = $this->normalizeRelativePath($prefix);
        $normalizedPath = $this->normalizeRelativePath($path);

        if ($normalizedPath === null) {
            return null;
        }

        if ($normalizedPrefix === null || $normalizedPrefix === '') {
            return $normalizedPath;
        }

        return $normalizedPrefix . '/' . $normalizedPath;
    }

    private function normalizeRelativePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = rawurlencode($segment);
        }

        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }
}
