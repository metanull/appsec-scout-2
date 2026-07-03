<?php

use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;

function tempAttachmentFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'assets-import-test-');
    file_put_contents($path, $contents);

    return $path;
}

it('fails with a clear message when the file does not exist', function () {
    $this->artisan('assets:import-attachment', [
        'source' => 'azdo',
        'system' => 'project-guid-1',
        'kind' => 'sbom',
        'file' => '/nonexistent/path/sbom.json',
    ])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);
});

it('fails when the software system does not exist and --system-name is omitted', function () {
    $path = tempAttachmentFile('{}');

    $this->artisan('assets:import-attachment', [
        'source' => 'azdo',
        'system' => 'project-guid-unknown',
        'kind' => 'sbom',
        'file' => $path,
    ])
        ->expectsOutputToContain('Provide --system-name to create it')
        ->assertExitCode(1);

    unlink($path);
});

it('creates a software system and attaches a file when --system-name is provided', function () {
    $path = tempAttachmentFile('{"components":[]}');

    $this->artisan('assets:import-attachment', [
        'source' => 'azdo',
        'system' => 'project-guid-2',
        'kind' => 'sbom',
        'file' => $path,
        '--system-name' => 'Payments Project',
    ])
        ->expectsOutputToContain('Attached')
        ->assertSuccessful();

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-guid-2')->first();

    expect($system)->not()->toBeNull()
        ->and($system?->name)->toBe('Payments Project')
        ->and(Attachment::query()->where('owner_type', SoftwareSystem::class)->where('owner_id', $system?->id)->count())->toBe(1);

    unlink($path);
});

it('converges with a software system row created by a live source sync', function () {
    $existing = SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-guid-3',
        'name' => 'Synced From AzDO',
    ]);

    $path = tempAttachmentFile('{"components":[]}');

    $this->artisan('assets:import-attachment', [
        'source' => 'azdo',
        'system' => 'project-guid-3',
        'kind' => 'sbom',
        'file' => $path,
    ])->assertSuccessful();

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-guid-3')->count())->toBe(1)
        ->and(Attachment::query()->where('owner_type', SoftwareSystem::class)->where('owner_id', $existing->id)->count())->toBe(1);

    unlink($path);
});

it('creates a security container and attaches a file when --container and --container-name are provided', function () {
    $path = tempAttachmentFile('lodash 4.17.19 -> 4.17.21');

    $this->artisan('assets:import-attachment', [
        'source' => 'azdo',
        'system' => 'project-guid-4',
        'kind' => 'dependency-report',
        'file' => $path,
        '--system-name' => 'Payments Project',
        '--container' => 'repo-guid-1',
        '--container-name' => 'payments-api',
        '--container-kind' => 'repository',
    ])->assertSuccessful();

    $container = SecurityContainer::query()->where('source_container_id', 'repo-guid-1')->first();

    expect($container)->not()->toBeNull()
        ->and($container?->name)->toBe('payments-api')
        ->and($container?->kind)->toBe('repository')
        ->and(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container?->id)->count())->toBe(1);

    unlink($path);
});
