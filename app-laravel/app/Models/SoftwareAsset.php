<?php

namespace App\Models;

use App\Audit\Recorder;
use Database\Factories\SoftwareAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['name', 'description', 'metadata'])]
class SoftwareAsset extends Model
{
    /** @use HasFactory<SoftwareAssetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (self $asset): void {
            app(Recorder::class)->recordAdminAction('software_asset_created', [
                'software_asset_id' => $asset->id,
                'name' => $asset->name,
            ]);
        });

        static::updated(function (self $asset): void {
            app(Recorder::class)->recordAdminAction('software_asset_updated', [
                'software_asset_id' => $asset->id,
                'name' => $asset->name,
                'changes' => $asset->getChanges(),
            ]);
        });

        static::deleting(function (SoftwareAsset $asset): void {
            app(Recorder::class)->recordAdminAction('software_asset_deleted', [
                'software_asset_id' => $asset->id,
                'name' => $asset->name,
            ]);

            $asset->trackerProjectLinks()->delete();
            $asset->repositoryMappings()->delete();
            $asset->curatedLinks()->delete();
            $asset->attachments()->delete();
            $asset->softwareComponents()->delete();
            $asset->localFindings()->delete();
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<SoftwareSystem, $this> */
    public function softwareSystems(): HasMany
    {
        return $this->hasMany(SoftwareSystem::class);
    }

    /** @return HasManyThrough<SecurityEvent, SoftwareSystem, $this> */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(SecurityEvent::class, SoftwareSystem::class, 'software_asset_id', 'software_system_id');
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

    /** @return HasMany<SoftwareComponent, $this> */
    public function softwareComponents(): HasMany
    {
        return $this->hasMany(SoftwareComponent::class);
    }

    /** @return HasMany<LocalFinding, $this> */
    public function localFindings(): HasMany
    {
        return $this->hasMany(LocalFinding::class);
    }
}
