<?php

use App\Sources\AzDo\AzDoRepository;

it('defaults isDisabled to false when the key is absent from the raw array', function () {
    $repo = AzDoRepository::fromArray([
        'id' => 'repo-001',
        'name' => 'backend-api',
    ]);

    expect($repo->isDisabled)->toBeFalse();
});

it('reads isDisabled from the raw array when present', function () {
    $repo = AzDoRepository::fromArray([
        'id' => 'repo-001',
        'name' => 'backend-api',
        'isDisabled' => true,
    ]);

    expect($repo->isDisabled)->toBeTrue();
});

it('defaults isDisabled to false on the constructor when omitted', function () {
    $repo = new AzDoRepository(id: 'repo-001', name: 'backend-api');

    expect($repo->isDisabled)->toBeFalse();
});
