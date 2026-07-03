<?php

namespace App\Models;

use Database\Factories\SoftwareSystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'software_asset_id', 'source_id', 'source_system_id', 'name', 'description',
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
            $system->attachments()->delete();
            $system->softwareComponents()->delete();
            $system->localFindings()->delete();
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

    /** @return BelongsTo<SoftwareAsset, $this> */
    public function softwareAsset(): BelongsTo
    {
        return $this->belongsTo(SoftwareAsset::class);
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
