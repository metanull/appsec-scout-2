<?php

namespace App\SourceCode;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;

final class RepositoryCodeUrlGenerator
{
    public function repositoryUrl(RepositoryMapping $mapping): ?string
    {
        $provider = $mapping->repositoryProvider;

        if (! $provider instanceof RepositoryProvider) {
            return null;
        }

        $providerType = $this->providerType($provider);
        if ($providerType === null) {
            return null;
        }

        $baseUrl = $this->baseUrl($provider);
        $repositoryName = $this->normalizeRelativePath($mapping->repository_name);

        if ($baseUrl === null || $repositoryName === null) {
            return null;
        }

        return match ($providerType) {
            RepositoryProviderType::GitHub => $baseUrl . '/' . $repositoryName,
            RepositoryProviderType::AzureRepos => $baseUrl . '/_git/' . $repositoryName,
        };
    }

    public function fileUrl(RepositoryMapping $mapping, CodeLocation $location): ?string
    {
        $provider = $mapping->repositoryProvider;

        if (! $provider instanceof RepositoryProvider) {
            return null;
        }

        $providerType = $this->providerType($provider);
        $baseUrl = $this->baseUrl($provider);
        $repositoryName = $this->normalizeRelativePath($mapping->repository_name);
        $fullPath = $this->joinPaths($mapping->path_prefix, $location->filePath);
        $ref = $this->resolveRef($mapping->default_branch, $location->branch, $location->commitSha);

        if ($providerType === null || $baseUrl === null || $repositoryName === null || $fullPath === null || $ref === null) {
            return null;
        }

        $url = match ($providerType) {
            RepositoryProviderType::GitHub => $baseUrl . '/' . $repositoryName . '/blob/' . rawurlencode($ref) . '/' . $fullPath,
            RepositoryProviderType::AzureRepos => $baseUrl . '/_git/' . $repositoryName . '?path=/' . $fullPath . '&version=' . rawurlencode($this->azureVersionRef($location->commitSha, $ref)),
        };

        if ($location->startLine === null) {
            return $url;
        }

        return match ($providerType) {
            RepositoryProviderType::GitHub => $url . '#L' . $location->startLine . ($location->endLine !== null && $location->endLine !== $location->startLine ? '-L' . $location->endLine : ''),
            RepositoryProviderType::AzureRepos => $url
                . '&line=' . $location->startLine
                . ($location->endLine !== null && $location->endLine !== $location->startLine ? '&lineEnd=' . $location->endLine : '')
                . '&lineStartColumn=1&lineEndColumn=1',
        };
    }

    public function commitUrl(RepositoryMapping $mapping, CodeLocation $location): ?string
    {
        if ($location->commitSha === null || $location->commitSha === '') {
            return null;
        }

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

        return match ($providerType) {
            RepositoryProviderType::GitHub => $baseUrl . '/' . $repositoryName . '/commit/' . rawurlencode($location->commitSha),
            RepositoryProviderType::AzureRepos => $baseUrl . '/_git/' . $repositoryName . '/commit/' . rawurlencode($location->commitSha),
        };
    }

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
