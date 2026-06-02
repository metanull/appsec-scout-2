<?php

namespace App\Models;

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
