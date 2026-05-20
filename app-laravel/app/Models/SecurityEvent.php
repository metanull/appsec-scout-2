<?php

namespace App\Models;

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

#[Fillable([
    'source_id', 'source_event_id', 'software_system_id', 'container_id',
    'title', 'description', 'severity', 'state', 'type', 'rule_id',
    'fingerprint', 'url', 'remediation',
    'file_path', 'start_line', 'end_line', 'snippet', 'commit_sha', 'branch', 'version_control_url',
    'source_data', 'metadata',
    'first_seen_at', 'last_seen_at', 'synced_at', 'updated_at',
    'is_dirty', 'pending_state', 'pending_comment',
])]
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
}
