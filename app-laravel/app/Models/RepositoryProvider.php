<?php

namespace App\Models;

use App\Models\Enums\RepositoryProviderType;
use App\SecurityEvents\SourceLinkHelper;
use Database\Factories\RepositoryProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['provider_type', 'name', 'base_url', 'metadata'])]
class RepositoryProvider extends Model
{
    /** @use HasFactory<RepositoryProviderFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RepositoryProvider $provider): void {
            $providerType = $provider->providerType();

            $provider->setAttribute('provider_type', $providerType->value);

            if (! SourceLinkHelper::isSafeUrl($provider->base_url)) {
                throw ValidationException::withMessages([
                    'base_url' => 'The base URL must use http or https.',
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'provider_type' => RepositoryProviderType::class,
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<RepositoryMapping, $this> */
    public function repositoryMappings(): HasMany
    {
        return $this->hasMany(RepositoryMapping::class);
    }

    private function providerType(): RepositoryProviderType
    {
        $rawProviderType = $this->getRawOriginal('provider_type');

        if ($rawProviderType instanceof RepositoryProviderType) {
            return $rawProviderType;
        }

        if (is_string($rawProviderType) && $rawProviderType !== '') {
            $providerType = RepositoryProviderType::tryFrom($rawProviderType);

            if ($providerType instanceof RepositoryProviderType) {
                return $providerType;
            }
        }

        $castProviderType = $this->getAttribute('provider_type');

        if ($castProviderType instanceof RepositoryProviderType) {
            return $castProviderType;
        }

        if (is_string($castProviderType) && $castProviderType !== '') {
            $providerType = RepositoryProviderType::tryFrom($castProviderType);

            if ($providerType instanceof RepositoryProviderType) {
                return $providerType;
            }
        }

        throw ValidationException::withMessages([
            'provider_type' => 'The selected provider type is invalid.',
        ]);
    }
}
