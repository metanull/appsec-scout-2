<?php

use App\Credentials\Credential;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Registry as TrackerRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    bindFakeCredentialIntegrationsForJsonCommands();
});

it('exports system credentials to json with expected structure', function () {
    Credential::query()->create([
        'integration_key' => 'fake.apiKey',
        'owner_user_id' => null,
        'value' => 'source-system-key',
    ]);

    Credential::query()->create([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => null,
        'value' => 'tracker-system-token',
    ]);

    $path = storage_path('app/testing/system-credentials-export.json');

    $this->artisan('credentials:system:export', ['path' => $path])
        ->assertSuccessful();

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['version'])->toBe(1)
        ->and($decoded['owner'])->toBe('system')
        ->and($decoded['integrations']['fake']['type'])->toBe('source')
        ->and($decoded['integrations']['fake']['fields']['fake.apiKey'])->toBe('source-system-key')
        ->and($decoded['integrations']['fake-tracker']['type'])->toBe('tracker')
        ->and($decoded['integrations']['fake-tracker']['fields']['fake-tracker.token'])->toBe('tracker-system-token');
});

it('imports system credentials from a valid exported structure', function () {
    $path = storage_path('app/testing/system-credentials-import.json');

    $this->artisan('credentials:system:export', ['path' => $path])
        ->assertSuccessful();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $integrations */
    $integrations = $payload['integrations'];

    /** @var array<string, mixed> $fakeSource */
    $fakeSource = $integrations['fake'];
    /** @var array<string, mixed> $fakeSourceFields */
    $fakeSourceFields = $fakeSource['fields'];
    $fakeSourceFields['fake.apiKey'] = 'imported-source-key';
    $fakeSource['fields'] = $fakeSourceFields;
    $integrations['fake'] = $fakeSource;

    /** @var array<string, mixed> $fakeTracker */
    $fakeTracker = $integrations['fake-tracker'];
    /** @var array<string, mixed> $fakeTrackerFields */
    $fakeTrackerFields = $fakeTracker['fields'];
    $fakeTrackerFields['fake-tracker.token'] = 'imported-tracker-token';
    $fakeTracker['fields'] = $fakeTrackerFields;
    $integrations['fake-tracker'] = $fakeTracker;

    $payload['integrations'] = $integrations;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    expect(file_exists($path))->toBeTrue();

    $exitCode = Artisan::call('credentials:system:import', ['path' => $path]);

    expect($exitCode)->toBe(0, Artisan::output());

    expect(Credential::query()->where('integration_key', 'fake.apiKey')->whereNull('owner_user_id')->first()?->value)
        ->toBe('imported-source-key')
        ->and(Credential::query()->where('integration_key', 'fake-tracker.token')->whereNull('owner_user_id')->first()?->value)
        ->toBe('imported-tracker-token');
});

it('fails import when json structure is invalid', function () {
    $path = storage_path('app/testing/system-credentials-invalid.json');

    $payload = [
        'version' => 1,
        'owner' => 'system',
        'integrations' => [
            'fake' => [
                'type' => 'source',
                'fields' => [
                    'fake.apiKey' => 'source-key',
                ],
            ],
            // missing fake-tracker block should fail strict validation
        ],
    ];

    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $this->artisan('credentials:system:import', ['path' => $path])
        ->assertFailed();
});

function bindFakeCredentialIntegrationsForJsonCommands(): void
{
    app()->bind('appsec-scout.source.fake', fn () => new FakeSource);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(SourceRegistry::class);
    app()->forgetInstance(TrackerRegistry::class);
}
