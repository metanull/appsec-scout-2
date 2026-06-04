<?php

namespace App\Models;

use App\Audit\Recorder;
use Database\Factories\RepositoryMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'owner_type',
    'owner_id',
    'repository_provider_id',
    'repository_name',
    'repository_url',
    'default_branch',
    'path_prefix',
    'created_by_user_id',
    'metadata',
])]
class RepositoryMapping extends Model
{
    /** @use HasFactory<RepositoryMappingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (self $mapping): void {
            app(Recorder::class)->recordAdminAction('repository_mapping_created', [
                'repository_mapping_id' => $mapping->id,
                'owner_type' => $mapping->owner_type,
                'owner_id' => $mapping->owner_id,
                'repository_provider_id' => $mapping->repository_provider_id,
                'repository_name' => $mapping->repository_name,
                'repository_url' => $mapping->repository_url,
                'default_branch' => $mapping->default_branch,
                'path_prefix' => $mapping->path_prefix,
                'created_by_user_id' => $mapping->created_by_user_id,
            ]);
        });

        static::updated(function (self $mapping): void {
            app(Recorder::class)->recordAdminAction('repository_mapping_updated', [
                'repository_mapping_id' => $mapping->id,
                'owner_type' => $mapping->owner_type,
                'owner_id' => $mapping->owner_id,
                'repository_provider_id' => $mapping->repository_provider_id,
                'repository_name' => $mapping->repository_name,
                'repository_url' => $mapping->repository_url,
                'default_branch' => $mapping->default_branch,
                'path_prefix' => $mapping->path_prefix,
                'changes' => $mapping->getChanges(),
            ]);
        });

        static::deleted(function (self $mapping): void {
            app(Recorder::class)->recordAdminAction('repository_mapping_deleted', [
                'repository_mapping_id' => $mapping->id,
                'owner_type' => $mapping->getRawOriginal('owner_type'),
                'owner_id' => $mapping->getRawOriginal('owner_id'),
                'repository_provider_id' => $mapping->getRawOriginal('repository_provider_id'),
                'repository_name' => $mapping->getRawOriginal('repository_name'),
                'repository_url' => $mapping->getRawOriginal('repository_url'),
                'default_branch' => $mapping->getRawOriginal('default_branch'),
                'path_prefix' => $mapping->getRawOriginal('path_prefix'),
            ]);
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<RepositoryProvider, $this> */
    public function repositoryProvider(): BelongsTo
    {
        return $this->belongsTo(RepositoryProvider::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
