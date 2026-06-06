<?php

namespace App\Models;

use App\Audit\AuditLog;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_id', 'source_event_id', 'software_system_id', 'container_id',
    'title', 'description', 'severity', 'state', 'type', 'rule_id',
    'fingerprint', 'url', 'remediation',
    'file_path', 'start_line', 'end_line', 'snippet', 'commit_sha', 'branch', 'version_control_url',
    'source_data', 'metadata',
    'first_seen_at', 'last_seen_at', 'synced_at', 'updated_at',
    'is_dirty', 'pending_state', 'pending_severity', 'pending_comment',
])]
/**
 * @property bool $is_dirty
 *
 * @mixin \Eloquent
 */
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'severity' => EventSeverity::class,
            'state' => EventState::class,
            'type' => EventType::class,
            'pending_state' => EventState::class,
            'pending_severity' => EventSeverity::class,
            'metadata' => 'array',
            'is_dirty' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
            'updated_at' => 'datetime',
            'start_line' => 'integer',
            'end_line' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SecurityEvent $event): void {
            $event->curatedLinks()->delete();
        });
    }

    /** @return BelongsTo<SoftwareSystem, $this> */
    public function softwareSystem(): BelongsTo
    {
        return $this->belongsTo(SoftwareSystem::class);
    }

    /** @return BelongsTo<SecurityContainer, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(SecurityContainer::class, 'container_id');
    }

    /** @return HasMany<EventComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class, 'event_id')->orderBy('created_at');
    }

    /** @return HasMany<WorkItemLink, $this> */
    public function workItemLinks(): HasMany
    {
        return $this->hasMany(WorkItemLink::class, 'event_id')->orderByDesc('created_at');
    }

    /** @return HasMany<EventAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(EventAttachment::class, 'event_id')->orderByDesc('created_at');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'subject_id', 'id')
            ->where('subject_type', static::class)
            ->orderByDesc('created_at');
    }

    /** @return MorphMany<CuratedLink, $this> */
    public function curatedLinks(): MorphMany
    {
        return $this->morphMany(CuratedLink::class, 'owner');
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', EventState::Open->value);
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public function scopeWithSeverity(Builder $query, EventSeverity $severity): Builder
    {
        return $query->where('severity', $severity->value);
    }

    /**
     * Order by severity priority: critical, high, medium, low, informational.
     *
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public function scopeBySeverityPriority(Builder $query): Builder
    {
        return $query->orderByRaw(
            "CASE severity WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'medium' THEN 3 WHEN 'low' THEN 2 WHEN 'informational' THEN 1 ELSE 0 END DESC"
        );
    }

    /**
     * Scope to events belonging to a virtual (linked) system.
     *
     * @param  Builder<SecurityEvent>  $query
     */
    public function scopeForVirtualSystem(Builder $query, int $linkId): void
    {
        $systemIds = SoftwareSystemLinkMember::where('link_id', $linkId)
            ->pluck('software_system_id');

        $query->whereIn('software_system_id', $systemIds);
    }

    /**
     * Scope to events belonging to member containers of a virtual container link.
     *
     * @param  Builder<SecurityEvent>  $query
     */
    public function scopeForVirtualContainer(Builder $query, int $linkId): void
    {
        $containerIds = SecurityContainerLinkMember::query()
            ->where('link_id', $linkId)
            ->pluck('security_container_id');

        $query->whereIn('container_id', $containerIds);
    }
}
