<?php

namespace App\Models;

use App\Filament\Support\EventSeverityBadgeColor;
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
    'file_path', 'start_line', 'end_line', 'dedup_hash',
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

    /**
     * dedup_hash is fully derivable from (rule_id, file_path, start_line), so every write path
     * gets a correct value for free here rather than each caller having to remember to compute
     * and set it — the column is NOT NULL and carries the real unique constraint (see the
     * local_findings migrations), so nothing should ever have to set it explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (self $finding): void {
            // Larastan infers dedup_hash as non-nullable from the DB column (NOT NULL), but at
            // this point in the lifecycle — before this hook fills it — an unset attribute is
            // genuinely null at runtime for any caller that didn't set it explicitly.
            // @phpstan-ignore identical.alwaysFalse
            if ($finding->dedup_hash === null) {
                $finding->dedup_hash = self::computeDedupHash(
                    (string) $finding->rule_id,
                    (string) $finding->file_path,
                    $finding->start_line,
                );
            }
        });
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
        $severity = $this->getAttribute('overridden_severity');

        return $severity instanceof EventSeverity ? $severity->name : strtoupper((string) $this->severity);
    }

    public static function severityColor(?string $severity): string
    {
        return EventSeverityBadgeColor::for($severity === null ? null : strtolower($severity));
    }

    /**
     * A short, fixed-width identity hash of a finding's (rule_id, file_path, start_line) —
     * narrow enough, unlike those columns at full width, to sit in a real composite unique
     * index alongside owner_type/owner_id/kind (see the local_findings migrations). The
     * NUL-byte separator avoids ambiguous concatenation collisions between adjacent fields
     * (e.g. ruleId: 'ab', filePath: 'c' vs ruleId: 'a', filePath: 'bc').
     */
    public static function computeDedupHash(string $ruleId, string $filePath, ?int $startLine): string
    {
        return sha1($ruleId . "\0" . $filePath . "\0" . ($startLine === null ? '' : (string) $startLine));
    }
}
