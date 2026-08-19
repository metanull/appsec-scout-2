<?php

use App\Filament\Support\LogLevelBadgeColor;

it('maps every known PSR-3 level to its badge color', function () {
    expect(LogLevelBadgeColor::for('emergency'))->toBe('danger')
        ->and(LogLevelBadgeColor::for('alert'))->toBe('danger')
        ->and(LogLevelBadgeColor::for('critical'))->toBe('danger')
        ->and(LogLevelBadgeColor::for('error'))->toBe('danger')
        ->and(LogLevelBadgeColor::for('warning'))->toBe('warning');
});

it('is case-insensitive', function () {
    expect(LogLevelBadgeColor::for('ERROR'))->toBe('danger')
        ->and(LogLevelBadgeColor::for('Warning'))->toBe('warning');
});

it('falls back to secondary for an unrecognized or null level', function () {
    expect(LogLevelBadgeColor::for('info'))->toBe('secondary')
        ->and(LogLevelBadgeColor::for(null))->toBe('secondary');
});
