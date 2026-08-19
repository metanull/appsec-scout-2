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

it('computes a deterministic dedup hash for the same inputs', function () {
    $hash = LocalFinding::computeDedupHash('CS0246', 'src/Program.cs', 42);

    expect(LocalFinding::computeDedupHash('CS0246', 'src/Program.cs', 42))->toBe($hash);
});

it('computes a different dedup hash when any input differs', function () {
    $base = LocalFinding::computeDedupHash('CS0246', 'src/Program.cs', 42);

    expect(LocalFinding::computeDedupHash('CS0247', 'src/Program.cs', 42))->not->toBe($base)
        ->and(LocalFinding::computeDedupHash('CS0246', 'src/Other.cs', 42))->not->toBe($base)
        ->and(LocalFinding::computeDedupHash('CS0246', 'src/Program.cs', 43))->not->toBe($base)
        ->and(LocalFinding::computeDedupHash('CS0246', 'src/Program.cs', null))->not->toBe($base);
});

it('does not collide across the rule_id/file_path boundary on plain concatenation', function () {
    expect(LocalFinding::computeDedupHash('ab', 'c', 1))->not->toBe(LocalFinding::computeDedupHash('a', 'bc', 1));
});
