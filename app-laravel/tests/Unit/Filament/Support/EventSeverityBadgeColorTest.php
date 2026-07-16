<?php

use App\Filament\Support\EventSeverityBadgeColor;
use App\Models\Enums\EventSeverity;

it('maps every EventSeverity case to its badge color', function () {
    expect(EventSeverityBadgeColor::for(EventSeverity::Critical))->toBe('danger')
        ->and(EventSeverityBadgeColor::for(EventSeverity::High))->toBe('warning')
        ->and(EventSeverityBadgeColor::for(EventSeverity::Medium))->toBe('info')
        ->and(EventSeverityBadgeColor::for(EventSeverity::Low))->toBe('gray')
        ->and(EventSeverityBadgeColor::for(EventSeverity::Informational))->toBe('secondary');
});

it('accepts the raw string value of an EventSeverity case', function () {
    expect(EventSeverityBadgeColor::for('critical'))->toBe('danger')
        ->and(EventSeverityBadgeColor::for('high'))->toBe('warning')
        ->and(EventSeverityBadgeColor::for('medium'))->toBe('info')
        ->and(EventSeverityBadgeColor::for('low'))->toBe('gray');
});

it('falls back to secondary for an unrecognized or null value', function () {
    expect(EventSeverityBadgeColor::for('something-unknown'))->toBe('secondary')
        ->and(EventSeverityBadgeColor::for(null))->toBe('secondary');
});
