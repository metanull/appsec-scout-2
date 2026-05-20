<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Sources\Detectify\DetectifyNormalizer;

it('normalizes severity and state mappings', function () {
    expect(DetectifyNormalizer::mapSeverity('critical'))->toBe(EventSeverity::Critical)
        ->and(DetectifyNormalizer::mapSeverity('information'))->toBe(EventSeverity::Informational)
        ->and(DetectifyNormalizer::mapState('patched'))->toBe(EventState::Resolved)
        ->and(DetectifyNormalizer::mapState('false_positive'))->toBe(EventState::Dismissed)
        ->and(DetectifyNormalizer::mapState('active'))->toBe(EventState::Open);
});

it('normalizes finding payload to event dto', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/Detectify/findings-domain-001.json')), true, 512, JSON_THROW_ON_ERROR);
    $finding = $payload['results'][1];
    $finding['asset_token'] = 'domain-001';

    $dto = DetectifyNormalizer::toEvent($finding);

    expect($dto->sourceEventId)->toBe('finding-002')
        ->and($dto->sourceSystemId)->toBe('domain-001')
        ->and($dto->type)->toBe(EventType::Misconfiguration)
        ->and($dto->state)->toBe(EventState::Dismissed)
        ->and($dto->ruleId)->toBe('CWE-16');
});
