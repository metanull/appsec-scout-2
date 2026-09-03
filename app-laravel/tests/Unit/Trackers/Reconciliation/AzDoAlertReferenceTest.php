<?php

use App\Trackers\Reconciliation\AzDoAlertReference;

it('parses the azdo portal alert url shape', function () {
    $reference = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398');

    expect($reference)->not->toBeNull()
        ->and($reference->organizationUrl)->toBe('https://dev.azure.com/acme')
        ->and($reference->projectRef)->toBe('agora')
        ->and($reference->repositoryRef)->toBe('agora-event-grid-poc')
        ->and($reference->alertId)->toBe('398');
});

it('parses the advanced security api alert url shape onto the same organization url', function () {
    $portal = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398');
    $api = AzDoAlertReference::fromUrl('https://advsec.dev.azure.com/acme/38ef0ca4-6d0e-4a1e-9b2f-2f0d0d19f6a1/_apis/AdvancedSecurity/repositories/7c3a9a75-4ac0-4f9f-9d29-9a0f2c5a4d13/alerts/398');

    expect($api)->not->toBeNull()
        ->and($api->organizationUrl)->toBe($portal->organizationUrl)
        ->and($api->projectRef)->toBe('38ef0ca4-6d0e-4a1e-9b2f-2f0d0d19f6a1')
        ->and($api->repositoryRef)->toBe('7c3a9a75-4ac0-4f9f-9d29-9a0f2c5a4d13')
        ->and($api->alertId)->toBe('398');
});

it('accepts a free form area segment in the advanced security api shape', function () {
    $reference = AzDoAlertReference::fromUrl('https://advsec.dev.azure.com/acme/proj-guid/_apis/Alert/repositories/repo-guid/Alerts/12');

    expect($reference)->not->toBeNull()
        ->and($reference->organizationUrl)->toBe('https://dev.azure.com/acme')
        ->and($reference->projectRef)->toBe('proj-guid')
        ->and($reference->repositoryRef)->toBe('repo-guid')
        ->and($reference->alertId)->toBe('12');
});

it('decodes and lowercases percent encoded project names', function () {
    $reference = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/My%20Project/_git/My%20Repo/alerts/7');

    expect($reference->projectRef)->toBe('my project')
        ->and($reference->repositoryRef)->toBe('my repo');
});

it('ignores query strings and fragments when building the key', function () {
    $plain = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/agora/_git/repo/alerts/398');
    $decorated = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/agora/_git/repo/alerts/398?_a=alerts&view=detail#tab');

    expect($decorated->key())->toBe($plain->key());
});

it('parses collection style organization paths', function () {
    $reference = AzDoAlertReference::fromUrl('https://tfs.contoso.com/tfs/DefaultCollection/Agora/_git/repo/alerts/5');

    expect($reference->organizationUrl)->toBe('https://tfs.contoso.com/tfs/defaultcollection')
        ->and($reference->projectRef)->toBe('agora');
});

it('returns null for urls that do not identify a single alert', function (string $url) {
    expect(AzDoAlertReference::fromUrl($url))->toBeNull();
})->with([
    'project root' => 'https://dev.azure.com/acme/Agora',
    'repository root' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc',
    'item url' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc?path=/src/Main.java',
    'non numeric alert id' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/settings',
    'alerts collection' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts',
    'api repository root' => 'https://advsec.dev.azure.com/acme/proj-guid/_apis/AdvancedSecurity/repositories/repo-guid',
    'non azdo url' => 'https://github.com/acme/repo/security/code-scanning/398',
    'non http scheme' => 'javascript:alert(1)',
    'not a url' => 'agora/_git/repo/alerts/398',
]);

it('produces one key for the guid and name variants of the same alert', function () {
    $guidForm = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/38ef0ca4-6d0e/_git/7c3a9a75-4ac0/alerts/398');
    $nameForm = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398');

    expect($guidForm->key())->not->toBe($nameForm->key())
        ->and($guidForm->withRefs('Agora', 'agora-event-grid-poc')->key())->toBe($nameForm->key())
        ->and($nameForm->withRefs('38ef0ca4-6d0e', '7c3a9a75-4ac0')->key())->toBe($guidForm->key());
});

it('keeps the organization and alert id when refs are replaced', function () {
    $reference = AzDoAlertReference::fromUrl('https://dev.azure.com/acme/Agora/_git/repo/alerts/398')
        ->withRefs('Other', 'OtherRepo');

    expect($reference->organizationUrl)->toBe('https://dev.azure.com/acme')
        ->and($reference->projectRef)->toBe('other')
        ->and($reference->repositoryRef)->toBe('otherrepo')
        ->and($reference->alertId)->toBe('398');
});
