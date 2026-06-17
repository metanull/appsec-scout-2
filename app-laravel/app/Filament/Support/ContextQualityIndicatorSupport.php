<?php

namespace App\Filament\Support;

use App\Context\Quality\ContextQualityService;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use Illuminate\Database\Eloquent\Model;

trait ContextQualityIndicatorSupport
{
    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    protected static function qualityIndicators(Model $record): array
    {
        return match (true) {
            $record instanceof SecurityEvent => app(ContextQualityService::class)->forSecurityEvent($record),
            $record instanceof SoftwareSystem => app(ContextQualityService::class)->forSoftwareSystem($record),
            $record instanceof SecurityContainer => app(ContextQualityService::class)->forSecurityContainer($record),
            $record instanceof SoftwareSystemLink => app(ContextQualityService::class)->forSoftwareSystemLink($record),
            $record instanceof SecurityContainerLink => app(ContextQualityService::class)->forSecurityContainerLink($record),
            default => [],
        };
    }

    protected static function qualitySummary(Model $record): string
    {
        $summaries = [];

        foreach (static::qualityIndicators($record) as $indicator) {
            $summaries[] = $indicator['label'] . ': ' . $indicator['message'];
        }

        return implode(' | ', $summaries);
    }

    protected static function qualityColor(Model $record): string
    {
        foreach (static::qualityIndicators($record) as $indicator) {
            if (in_array($indicator['color'], ['warning', 'danger'], true)) {
                return $indicator['color'];
            }
        }

        return 'success';
    }
}
