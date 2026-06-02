<?php

namespace App\Models;

use App\Models\Enums\RepositoryProviderType;
use Database\Factories\RepositoryProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['provider_type', 'name', 'base_url', 'metadata'])]
class RepositoryProvider extends Model
{
    /** @use HasFactory<RepositoryProviderFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RepositoryProvider $provider): void {
            $providerType = $provider->getAttributes()['provider_type'] ?? null;

            if ($providerType instanceof RepositoryProviderType) {
                $provider->setAttribute('provider_type', $providerType->value);

                return;
            }

            if (is_string($providerType)) {
                $provider->setAttribute('provider_type', RepositoryProviderType::from($providerType)->value);
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
}
