<?php

use App\Assets\Parsers\CycloneDxSbomParser;

function cycloneDxFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json'));
}

it('parses components with a purl and skips scan-artifact descriptors without one', function () {
    $components = (new CycloneDxSbomParser)->parse(cycloneDxFixture());

    expect($components)->toHaveCount(2);

    expect($components[0]->name)->toBe('System.DirectoryServices.Protocols')
        ->and($components[0]->version)->toBe('8.0.2')
        ->and($components[0]->ecosystem)->toBe('nuget')
        ->and($components[0]->purl)->toBe('pkg:nuget/System.DirectoryServices.Protocols@8.0.2')
        ->and($components[0]->license)->toBe('MIT');

    expect($components[1]->name)->toBe('Microsoft.EntityFrameworkCore')
        ->and($components[1]->license)->toBeNull();
});

it('returns an empty list for invalid json', function () {
    expect((new CycloneDxSbomParser)->parse('not json'))->toBe([]);
});

it('returns an empty list when there are no components', function () {
    expect((new CycloneDxSbomParser)->parse('{"bomFormat":"CycloneDX"}'))->toBe([]);
});
