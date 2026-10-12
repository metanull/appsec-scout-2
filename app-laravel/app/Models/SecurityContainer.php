<?php

namespace App\Models;

use Database\Factories\SecurityContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'software_system_id', 'source_software_system_id', 'system_overridden_at', 'source_container_id', 'name', 'kind',
    'url', 'metadata', 'first_seen_at', 'last_seen_at', 'synced_at', 'removed_at',
])]
class SecurityContainer extends Model
{
    /** @use HasFactory<SecurityContainerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // A container is first linked to the system its source reports; that system stays its
        // sync identity (source_software_system_id) even after an operator re-links it.
        static::creating(function (SecurityContainer $container): void {
            $container->source_software_system_id ??= $container->software_system_id;
        });

        static::deleting(function (SecurityContainer $container): void {
            $container->trackerProjectLinks()->delete();
            $container->repositoryMappings()->delete();
            $container->curatedLinks()->delete();
            $container->attachments()->delete();
            $container->softwareComponents()->delete();
            $container->localFindings()->delete();
            $container->staticAnalysisState()->delete();
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
            'system_overridden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SoftwareSystem, $this> the system the container is linked to */
    public function softwareSystem(): BelongsTo
    {
        return $this->belongsTo(SoftwareSystem::class);
    }

    /** @return BelongsTo<SoftwareSystem, $this> the system the container's source reports it under */
    public function sourceSoftwareSystem(): BelongsTo
    {
        return $this->belongsTo(SoftwareSystem::class, 'source_software_system_id');
    }

    /** True when an operator re-linked the container to a system other than its source's. */
    public function isSystemOverridden(): bool
    {
        return $this->getAttribute('system_overridden_at') !== null;
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

    /** @return HasOne<StaticAnalysisRepositoryState, $this> */
    public function staticAnalysisState(): HasOne
    {
        return $this->hasOne(StaticAnalysisRepositoryState::class);
    }
}
