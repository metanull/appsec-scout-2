<?php

use App\Filament\Support\RunStatusBadgeColor;

it('maps every known run status to its badge color', function () {
    expect(RunStatusBadgeColor::for('success'))->toBe('success')
        ->and(RunStatusBadgeColor::for('failure'))->toBe('danger')
        ->and(RunStatusBadgeColor::for('partial'))->toBe('warning')
        ->and(RunStatusBadgeColor::for('running'))->toBe('warning');
});

it('falls back to warning for an unrecognized or null status', function () {
    expect(RunStatusBadgeColor::for('something-unknown'))->toBe('warning')
        ->and(RunStatusBadgeColor::for(null))->toBe('warning');
});
