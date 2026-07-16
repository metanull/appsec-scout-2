<?php

use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventState;

it('maps every EventState case to its badge color', function () {
    expect(EventStateBadgeColor::for(EventState::Resolved))->toBe('success')
        ->and(EventStateBadgeColor::for(EventState::Dismissed))->toBe('gray')
        ->and(EventStateBadgeColor::for(EventState::InProgress))->toBe('info')
        ->and(EventStateBadgeColor::for(EventState::Acknowledged))->toBe('warning')
        ->and(EventStateBadgeColor::for(EventState::Open))->toBe('danger');
});

it('accepts the raw string value of an EventState case', function () {
    expect(EventStateBadgeColor::for('resolved'))->toBe('success')
        ->and(EventStateBadgeColor::for('dismissed'))->toBe('gray')
        ->and(EventStateBadgeColor::for('in_progress'))->toBe('info')
        ->and(EventStateBadgeColor::for('acknowledged'))->toBe('warning')
        ->and(EventStateBadgeColor::for('open'))->toBe('danger');
});

it('falls back to danger for an unrecognized or null value', function () {
    expect(EventStateBadgeColor::for('something-unknown'))->toBe('danger')
        ->and(EventStateBadgeColor::for(null))->toBe('danger');
});
