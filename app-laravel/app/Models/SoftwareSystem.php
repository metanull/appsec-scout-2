<?php

namespace App\Models;

use Database\Factories\SoftwareSystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_id', 'source_system_id', 'name', 'description',
    'url', 'metadata', 'first_seen_at', 'last_seen_at', 'synced_at',
])]
class SoftwareSystem extends Model
{
    /** @use HasFactory<SoftwareSystemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (SoftwareSystem $system): void {
            $system->trackerProjectLinks()->delete();
            $system->repositoryMappings()->delete();
            $system->curatedLinks()->delete();
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<SecurityContainer, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(SecurityContainer::class);
    }

    /** @return HasMany<SecurityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    /** @return MorphMany<TrackerProjectLink, $this> */
    public function trackerProjectLinks(): MorphMany
    {
        return $this->morphMany(TrackerProjectLink::class, 'owner');
    }

    /** @return MorphMany<RepositoryMapping, $this> */
    public function repositoryMappings(): MorphMany
    {
        return $this->morphMany(RepositoryMapping::class, 'owner');
    }

    /** @return MorphMany<CuratedLink, $this> */
    public function curatedLinks(): MorphMany
    {
        return $this->morphMany(CuratedLink::class, 'owner');
    }

    /** @return BelongsToMany<SoftwareSystemLink, $this> */
    public function links(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareSystemLink::class, 'software_system_link_members', 'software_system_id', 'link_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
