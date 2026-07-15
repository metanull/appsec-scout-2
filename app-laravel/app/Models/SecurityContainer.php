<?php

namespace App\Models;

use Database\Factories\SecurityContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'software_system_id', 'source_container_id', 'name', 'kind',
    'url', 'metadata', 'first_seen_at', 'last_seen_at', 'synced_at', 'removed_at',
])]
class SecurityContainer extends Model
{
    /** @use HasFactory<SecurityContainerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (SecurityContainer $container): void {
            $container->trackerProjectLinks()->delete();
            $container->repositoryMappings()->delete();
            $container->curatedLinks()->delete();
            $container->attachments()->delete();
            $container->softwareComponents()->delete();
            $container->localFindings()->delete();
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
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SoftwareSystem, $this> */
    public function softwareSystem(): BelongsTo
    {
        return $this->belongsTo(SoftwareSystem::class);
    }

    /** @return HasMany<SecurityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'container_id');
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

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }

    /** @return MorphMany<SoftwareComponent, $this> */
    public function softwareComponents(): MorphMany
    {
        return $this->morphMany(SoftwareComponent::class, 'owner');
    }

    /** @return MorphMany<LocalFinding, $this> */
    public function localFindings(): MorphMany
    {
        return $this->morphMany(LocalFinding::class, 'owner');
    }
}
