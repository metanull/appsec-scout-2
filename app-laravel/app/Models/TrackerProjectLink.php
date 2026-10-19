<?php

namespace App\Models;

use App\Audit\Recorder;
use App\Sources\Context\SourceContextFacts;
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

    /** Metadata key recording how a link was created; absent on links added by hand. */
    public const string METADATA_ORIGIN = 'origin';

    /** Metadata key holding the browsable URL of the tracker project, as the tracker reported it. */
    public const string METADATA_PROJECT_URL = 'tracker.project_url';

    /** Learned when a work item was created or linked from an alert or finding. */
    public const string ORIGIN_WORK_ITEM = 'work_item';

    /** Learned by reconciliation from the project of a tracker issue that references an alert. */
    public const string ORIGIN_RECONCILIATION = 'reconciliation';

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
                'origin' => $link->origin(),
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

    public function origin(): ?string
    {
        return $this->metadataString(self::METADATA_ORIGIN);
    }

    /**
     * Whether only reconciliation suggested this link. Such a link never outranks one added
     * by hand or learned from a work item when a default tracker project is resolved.
     */
    public function isLearnedByReconciliation(): bool
    {
        return $this->origin() === self::ORIGIN_RECONCILIATION;
    }

    public function projectUrl(): ?string
    {
        return $this->metadataString(self::METADATA_PROJECT_URL);
    }

    private function metadataString(string $key): ?string
    {
        $metadata = $this->getAttribute('metadata');

        if (! is_array($metadata)) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        return SourceContextFacts::getString($metadata, $key);
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
