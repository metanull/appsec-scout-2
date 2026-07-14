<?php

namespace App\Models;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'owner_type', 'owner_id', 'attachment_id',
    'software_system_id', 'software_asset_id',
    'kind', 'rule_id', 'title', 'description', 'severity',
    'file_path', 'start_line', 'end_line',
    'package_name', 'package_version', 'metadata',
    'correlated_security_event_id', 'correlation_method',
    'status', 'overridden_severity',
    'first_seen_at', 'last_seen_at',
])]
class LocalFinding extends Model
{
    public const KIND_VULNERABILITY = 'vulnerability';

    public const KIND_SECRET = 'secret';

    public const KIND_CODE_QUALITY = 'code_quality';

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'start_line' => 'integer',
            'end_line' => 'integer',
            'status' => EventState::class,
            'overridden_severity' => EventSeverity::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<SoftwareSystem, $this> */
    public function softwareSystem(): BelongsTo
    {
        return $this->belongsTo(SoftwareSystem::class);
    }

    /** @return BelongsTo<SoftwareAsset, $this> */
    public function softwareAsset(): BelongsTo
    {
        return $this->belongsTo(SoftwareAsset::class);
    }

    /** @return BelongsTo<SecurityEvent, $this> */
    public function correlatedSecurityEvent(): BelongsTo
    {
        return $this->belongsTo(SecurityEvent::class, 'correlated_security_event_id');
    }

    /** @return HasMany<LocalFindingComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(LocalFindingComment::class)->orderBy('created_at');
    }

    /** @return HasMany<LocalFindingWorkItemLink, $this> */
    public function workItemLinks(): HasMany
    {
        return $this->hasMany(LocalFindingWorkItemLink::class)->orderByDesc('created_at');
    }

    /**
     * The severity an operator has manually set, or the scanner-reported severity if none was
     * set. Re-scanning never touches `overridden_severity`, so an operator's call survives it.
     */
    public function effectiveSeverityLabel(): string
    {
        return $this->overridden_severity?->name ?? strtoupper((string) $this->severity);
    }

    public static function severityColor(?string $severity): string
    {
        return match (strtoupper((string) $severity)) {
            'CRITICAL' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            'LOW', 'UNKNOWN' => 'gray',
            default => 'gray',
        };
    }
}
