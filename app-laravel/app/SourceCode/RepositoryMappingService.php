<?php

namespace App\SourceCode;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\SourceLinkHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RepositoryMappingService
{
    /**
     * @param  array{repository_provider_id?: mixed, repository_name?: mixed, default_branch?: mixed, path_prefix?: mixed}  $data
     */
    public function create(SoftwareSystem|SecurityContainer $owner, User $author, array $data): RepositoryMapping
    {
        $payload = $this->normalizePayload($data);
        $provider = $this->resolveProvider($payload['repository_provider_id']);
        $repositoryUrl = $this->buildRepositoryUrl($provider, $payload['repository_name']);

        $this->ensureUniqueMapping($owner, $provider->id, $payload['repository_name']);
        $this->ensureSafeRepositoryUrl($repositoryUrl);

        return DB::transaction(function () use ($owner, $author, $payload, $provider, $repositoryUrl): RepositoryMapping {
            return $owner->repositoryMappings()->create([
                'repository_provider_id' => $provider->id,
                'repository_name' => $payload['repository_name'],
                'repository_url' => $repositoryUrl,
                'default_branch' => $payload['default_branch'],
                'path_prefix' => $payload['path_prefix'],
                'created_by_user_id' => $author->id,
            ])->refresh();
        });
    }

    /**
     * @param  array{repository_provider_id?: mixed, repository_name?: mixed, default_branch?: mixed, path_prefix?: mixed}  $data
     */
    public function update(RepositoryMapping $mapping, array $data): RepositoryMapping
    {
        $payload = $this->normalizePayload($data);
        $provider = $this->resolveProvider($payload['repository_provider_id']);
        $repositoryUrl = $this->buildRepositoryUrl($provider, $payload['repository_name']);
        $owner = $this->resolveOwner($mapping);

        $this->ensureUniqueMapping($owner, $provider->id, $payload['repository_name'], $mapping);
        $this->ensureSafeRepositoryUrl($repositoryUrl);

        return DB::transaction(function () use ($mapping, $payload, $provider, $repositoryUrl): RepositoryMapping {
            $mapping->forceFill([
                'repository_provider_id' => $provider->id,
                'repository_name' => $payload['repository_name'],
                'repository_url' => $repositoryUrl,
                'default_branch' => $payload['default_branch'],
                'path_prefix' => $payload['path_prefix'],
            ])->save();

            return $mapping->refresh();
        });
    }

    public function delete(RepositoryMapping $mapping): void
    {
        DB::transaction(static function () use ($mapping): void {
            $mapping->delete();
        });
    }

    /**
     * @param  array{repository_provider_id?: mixed, repository_name?: mixed, default_branch?: mixed, path_prefix?: mixed}  $data
     * @return array{repository_provider_id: int, repository_name: string, default_branch: string, path_prefix: ?string}
     */
    private function normalizePayload(array $data): array
    {
        $providerId = $this->normalizeInteger($data['repository_provider_id'] ?? null, 'repository_provider_id');

        $repositoryName = $this->normalizeRequiredPath($data['repository_name'] ?? null, 'repository_name');
        $defaultBranch = $this->normalizeRequiredText($data['default_branch'] ?? null, 'default_branch');
        $pathPrefix = $this->normalizeOptionalPath($data['path_prefix'] ?? null, 'path_prefix');

        return [
            'repository_provider_id' => $providerId,
            'repository_name' => $repositoryName,
            'default_branch' => $defaultBranch,
            'path_prefix' => $pathPrefix,
        ];
    }

    private function resolveProvider(int $providerId): RepositoryProvider
    {
        $provider = RepositoryProvider::query()->find($providerId);

        if (! $provider instanceof RepositoryProvider) {
            throw ValidationException::withMessages([
                'repository_provider_id' => 'The selected repository provider is invalid.',
            ]);
        }

        $providerType = RepositoryProviderType::tryFrom((string) $provider->getRawOriginal('provider_type'));

        if (! $providerType instanceof RepositoryProviderType) {
            throw ValidationException::withMessages([
                'repository_provider_id' => 'The selected repository provider type is invalid.',
            ]);
        }

        if (! SourceLinkHelper::isSafeUrl($provider->base_url)) {
            throw ValidationException::withMessages([
                'repository_provider_id' => 'The selected repository provider has an unsafe base URL.',
            ]);
        }

        return $provider;
    }

    private function ensureUniqueMapping(Model $owner, int $providerId, string $repositoryName, ?RepositoryMapping $ignore = null): void
    {
        $query = RepositoryMapping::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', $owner->getKey())
            ->where('repository_provider_id', $providerId)
            ->where('repository_name', $repositoryName);

        if ($ignore instanceof RepositoryMapping) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'repository_name' => 'This repository mapping already exists for the selected owner and provider.',
            ]);
        }
    }

    private function ensureSafeRepositoryUrl(string $repositoryUrl): void
    {
        if (! SourceLinkHelper::isSafeUrl($repositoryUrl)) {
            throw ValidationException::withMessages([
                'repository_url' => 'The generated repository URL is unsafe.',
            ]);
        }
    }

    private function normalizeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw ValidationException::withMessages([
            $field => 'The selected repository provider is invalid.',
        ]);
    }

    private function normalizeRequiredText(mixed $value, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => 'This field is required.',
            ]);
        }

        return $text;
    }

    private function normalizeRequiredPath(mixed $value, string $field): string
    {
        $path = $this->normalizePath($value, false, $field);

        if ($path === null) {
            throw ValidationException::withMessages([
                $field => 'This field is required.',
            ]);
        }

        return $path;
    }

    private function normalizeOptionalPath(mixed $value, string $field): ?string
    {
        $path = $this->normalizePath($value, true, $field);

        if ($path === null) {
            return null;
        }

        if ($path === '') {
            return null;
        }

        return $path;
    }

    private function normalizePath(mixed $value, bool $allowEmpty, string $field): ?string
    {
        if ($value === null) {
            return $allowEmpty ? null : '';
        }

        $path = trim(str_replace('\\', '/', (string) $value));

        if ($path === '') {
            return $allowEmpty ? null : '';
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw ValidationException::withMessages([
                    $field => 'Path segments cannot traverse outside the repository.',
                ]);
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return $allowEmpty ? null : '';
        }

        return implode('/', $segments);
    }

    private function buildRepositoryUrl(RepositoryProvider $provider, string $repositoryName): string
    {
        $normalizedBaseUrl = rtrim($provider->base_url, '/');
        $normalizedRepositoryName = $this->encodePath($repositoryName);
        $providerType = RepositoryProviderType::from((string) $provider->getRawOriginal('provider_type'));

        return match ($providerType) {
            RepositoryProviderType::AzureRepos => $normalizedBaseUrl . '/_git/' . $normalizedRepositoryName,
            RepositoryProviderType::GitHub => $normalizedBaseUrl . '/' . $normalizedRepositoryName,
        };
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path),
        ));
    }

    private function resolveOwner(RepositoryMapping $mapping): SoftwareSystem|SecurityContainer
    {
        $owner = $mapping->owner;

        if ($owner instanceof SoftwareSystem || $owner instanceof SecurityContainer) {
            return $owner;
        }

        throw ValidationException::withMessages([
            'repository_name' => 'The repository mapping owner could not be resolved.',
        ]);
    }
}
