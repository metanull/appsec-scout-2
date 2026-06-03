<?php

namespace App\Models;

use App\Audit\Recorder;
use Database\Factories\TrackerProjectLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'owner_type',
    'owner_id',
    'tracker_id',
    'project_key',
    'project_name',
    'is_default',
    'created_by_user_id',
    'metadata',
])]
class TrackerProjectLink extends Model
{
    /** @use HasFactory<TrackerProjectLinkFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (self $link): void {
            app(Recorder::class)->recordAdminAction('tracker_project_link_created', [
                'tracker_project_link_id' => $link->id,
                'owner_type' => $link->owner_type,
                'owner_id' => $link->owner_id,
                'tracker_id' => $link->tracker_id,
                'project_key' => $link->project_key,
                'project_name' => $link->project_name,
                'is_default' => $link->is_default,
                'created_by_user_id' => $link->created_by_user_id,
            ]);
        });

        static::updated(function (self $link): void {
            app(Recorder::class)->recordAdminAction('tracker_project_link_updated', [
                'tracker_project_link_id' => $link->id,
                'owner_type' => $link->owner_type,
                'owner_id' => $link->owner_id,
                'tracker_id' => $link->tracker_id,
                'project_key' => $link->project_key,
                'project_name' => $link->project_name,
                'is_default' => $link->is_default,
                'changes' => $link->getChanges(),
            ]);
        });

        static::deleted(function (self $link): void {
            app(Recorder::class)->recordAdminAction('tracker_project_link_deleted', [
                'tracker_project_link_id' => $link->id,
                'owner_type' => $link->getRawOriginal('owner_type'),
                'owner_id' => $link->getRawOriginal('owner_id'),
                'tracker_id' => $link->getRawOriginal('tracker_id'),
                'project_key' => $link->getRawOriginal('project_key'),
                'project_name' => $link->getRawOriginal('project_name'),
                'is_default' => $link->getRawOriginal('is_default'),
            ]);
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
