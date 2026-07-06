<?php

use App\Assets\Parsers\CycloneDxSbomParser;

function cycloneDxFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json'));
}

it('parses components with a purl and skips scan-artifact descriptors without one', function () {
    $components = (new CycloneDxSbomParser)->parse(cycloneDxFixture());

    expect($components)->toHaveCount(4);

    expect($components[0]->name)->toBe('System.DirectoryServices.Protocols')
        ->and($components[0]->version)->toBe('8.0.2')
        ->and($components[0]->ecosystem)->toBe('nuget')
        ->and($components[0]->purl)->toBe('pkg:nuget/System.DirectoryServices.Protocols@8.0.2')
        ->and($components[0]->license)->toBe('MIT');

    expect($components[1]->name)->toBe('Microsoft.EntityFrameworkCore')
        ->and($components[1]->license)->toBeNull();
});

it('synthesizes a pkg:generic purl for operating-system/platform/framework components', function () {
    $components = (new CycloneDxSbomParser)->parse(cycloneDxFixture());

    $runtime = collect($components)->firstWhere('name', 'Microsoft.NETCore.App');

    expect($runtime)->not->toBeNull()
        ->and($runtime->version)->toBe('8.0.28')
        ->and($runtime->ecosystem)->toBe('operating-system')
        ->and($runtime->purl)->toBe('pkg:generic/Microsoft.NETCore.App@8.0.28');

    $framework = collect($components)->firstWhere('name', 'alpine');

    expect($framework)->not->toBeNull()
        ->and($framework->version)->toBeNull()
        ->and($framework->ecosystem)->toBe('framework')
        ->and($framework->purl)->toBe('pkg:generic/alpine');
});

it('still skips application-type components without a purl', function () {
    $components = (new CycloneDxSbomParser)->parse(cycloneDxFixture());

    expect(collect($components)->pluck('name'))->not->toContain('src/DiffingTool.Service/packages.lock.json');
});

it('returns an empty list for invalid json', function () {
    expect((new CycloneDxSbomParser)->parse('not json'))->toBe([]);
});

it('returns an empty list when there are no components', function () {
    expect((new CycloneDxSbomParser)->parse('{"bomFormat":"CycloneDX"}'))->toBe([]);
});
