<?php

use App\Filament\Support\ActorKindBadgeColor;

it('maps every known actor kind to its badge color', function () {
    expect(ActorKindBadgeColor::for('user'))->toBe('info')
        ->and(ActorKindBadgeColor::for('job'))->toBe('gray')
        ->and(ActorKindBadgeColor::for('cli'))->toBe('warning')
        ->and(ActorKindBadgeColor::for('system'))->toBe('secondary');
});

it('falls back to secondary for an unrecognized or null actor kind', function () {
    expect(ActorKindBadgeColor::for('something-unknown'))->toBe('secondary')
        ->and(ActorKindBadgeColor::for(null))->toBe('secondary');
});
