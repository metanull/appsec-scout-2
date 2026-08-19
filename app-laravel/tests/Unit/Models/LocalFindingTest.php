<?php

use App\Models\LocalFinding;

it('maps every effective severity label to its shared badge color, case-insensitively', function () {
    expect(LocalFinding::severityColor('CRITICAL'))->toBe('danger')
        ->and(LocalFinding::severityColor('HIGH'))->toBe('warning')
        ->and(LocalFinding::severityColor('MEDIUM'))->toBe('info')
        ->and(LocalFinding::severityColor('LOW'))->toBe('gray')
        ->and(LocalFinding::severityColor('critical'))->toBe('danger');
});

it('falls back to secondary for an unrecognized or null severity', function () {
    expect(LocalFinding::severityColor('UNKNOWN'))->toBe('secondary')
        ->and(LocalFinding::severityColor(null))->toBe('secondary');
});
