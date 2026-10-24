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

it('identifies a vulnerability by advisory, manifest, package and version, not by its line', function () {
    $hash = fn (?int $line, string $version = '10.1.0', string $rule = 'CVE-2026-101911') => LocalFinding::identityHash(LocalFinding::KIND_VULNERABILITY, $rule, 'package-lock.json', $line, 'ip-address', $version);

    expect($hash(1063))->toBe($hash(2210))
        ->and($hash(1063, '10.1.1'))->not->toBe($hash(1063))
        ->and($hash(1063, '10.1.0', 'CVE-2026-100001'))->not->toBe($hash(1063))
        ->and($hash(1063))->not->toBe(LocalFinding::computeDedupHash('CVE-2026-101911', 'package-lock.json', 1063));
});

it('identifies a code finding by rule, file and line', function () {
    expect(LocalFinding::identityHash(LocalFinding::KIND_CODE_QUALITY, 'js.eval-detected', 'src/app.js', 3, null, null))
        ->toBe(LocalFinding::computeDedupHash('js.eval-detected', 'src/app.js', 3));
});

it('ignores a stored scanner fingerprint when computing a row\'s identity', function () {
    $finding = new LocalFinding([
        'kind' => LocalFinding::KIND_CODE_QUALITY,
        'rule_id' => 'js.eval-detected',
        'file_path' => 'src/app.js',
        'start_line' => 3,
        'metadata' => ['result' => ['fingerprints' => ['matchBasedId/v1' => '27d65b71fe30_0']]],
    ]);

    expect($finding->currentIdentityHash())->toBe(LocalFinding::computeDedupHash('js.eval-detected', 'src/app.js', 3));
});

it('keeps the line in the identity of a secret or vulnerability without package', function () {
    expect(LocalFinding::identityHash(LocalFinding::KIND_SECRET, 'github-pat', 'config.php', 12, null, null))
        ->toBe(LocalFinding::computeDedupHash('github-pat', 'config.php', 12))
        ->and(LocalFinding::identityHash(LocalFinding::KIND_VULNERABILITY, 'CVE-1', 'go.sum', 7, 'pkg', null))
        ->toBe(LocalFinding::computeDedupHash('CVE-1', 'go.sum', 7));
});
