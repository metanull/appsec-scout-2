<?php

use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Models\Enums\EventType;

it('selects expected sections for every event type', function (EventType $type, array $expected) {
    expect(ViewSecurityEvent::sectionsForType($type))->toBe($expected);
})->with([
    [EventType::Secret, ['universal', 'secret', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::Dependency, ['universal', 'dependency', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::Vulnerability, ['universal', 'code_location', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::CodeQuality, ['universal', 'code_location', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::Misconfiguration, ['universal', 'posture', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::Iac, ['universal', 'posture', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::Posture, ['universal', 'posture', 'remediation', 'comments', 'audit', 'work_items']],
    [EventType::License, ['universal', 'remediation', 'comments', 'audit', 'work_items']],
]);
