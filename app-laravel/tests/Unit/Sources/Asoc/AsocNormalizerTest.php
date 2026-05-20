<?php

use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Sources\Asoc\AsocNormalizer;

it('normalizes all ASoC issue variants', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/Asoc/issues-variants.json')), true, 512, JSON_THROW_ON_ERROR);
    $items = $payload['Items'];

    $events = array_map(static fn (array $issue) => AsocNormalizer::toEvent($issue, 'app-001'), $items);

    expect($events[0]->type)->toBe(EventType::Vulnerability)
        ->and($events[0]->filePath)->toBe('src/Repositories/UserRepo.php')
        ->and($events[0]->startLine)->toBe(42)
        ->and($events[0]->metadata['cwe'])->toBe('CWE-89');

    expect($events[1]->type)->toBe(EventType::Dependency)
        ->and($events[1]->metadata['cve'])->toBe('CVE-2024-9999');

    expect($events[2]->type)->toBe(EventType::Secret)
        ->and($events[2]->metadata['fingerprint'])->toBe('sec-fp-1')
        ->and($events[2]->state)->toBe(EventState::Open);

    expect($events[3]->type)->toBe(EventType::Vulnerability)
        ->and($events[3]->metadata['apiVulnName'])->toBe('Missing HSTS')
        ->and($events[3]->state)->toBe(EventState::InProgress);

    expect($events[4]->type)->toBe(EventType::Misconfiguration)
        ->and($events[4]->state)->toBe(EventState::Dismissed);
});
