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

it('reads message, help, tags and level from metadata', function () {
    $finding = new LocalFinding([
        'metadata' => [
            'message' => 'Concrete diagnostic text.',
            'help' => 'Fix it like this.',
            'tags' => ['security', 'owasp-a03'],
            'level' => 'error',
        ],
    ]);

    expect($finding->messageText())->toBe('Concrete diagnostic text.')
        ->and($finding->helpMarkdown())->toBe('Fix it like this.')
        ->and($finding->tags())->toBe(['security', 'owasp-a03'])
        ->and($finding->sarifLevel())->toBe('error');
});

it('falls back messageText to the legacy metadata.result.message.text for rows without a captured message', function () {
    $finding = new LocalFinding([
        'metadata' => [
            'result' => ['message' => ['text' => 'Legacy diagnostic text.']],
        ],
    ]);

    expect($finding->messageText())->toBe('Legacy diagnostic text.');
});

it('defaults message, help, tags and level to empty when metadata carries none of them', function () {
    $finding = new LocalFinding(['metadata' => []]);

    expect($finding->messageText())->toBeNull()
        ->and($finding->helpMarkdown())->toBeNull()
        ->and($finding->tags())->toBe([])
        ->and($finding->sarifLevel())->toBeNull();
});

it('defaults message, help, tags and level to empty when metadata is null', function () {
    $finding = new LocalFinding(['metadata' => null]);

    expect($finding->messageText())->toBeNull()
        ->and($finding->helpMarkdown())->toBeNull()
        ->and($finding->tags())->toBe([])
        ->and($finding->sarifLevel())->toBeNull();
});
