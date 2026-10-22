<?php

use App\Assets\Sbom\ContainerSbomMerger;
use App\Models\SecurityContainer;
use Carbon\CarbonInterface;

/**
 * @param  list<array<string, mixed>>  $components
 * @param  list<array<string, mixed>>  $dependencies
 */
function mergerCycloneDx(string $root, array $components, array $dependencies = [], string $specVersion = '1.6'): string
{
    return json_encode([
        'bomFormat' => 'CycloneDX',
        'specVersion' => $specVersion,
        'serialNumber' => "urn:uuid:{$root}",
        'metadata' => ['component' => ['bom-ref' => $root, 'type' => 'application', 'name' => $root]],
        'components' => $components,
        'dependencies' => $dependencies,
    ], JSON_THROW_ON_ERROR);
}

function mergerComponent(string $ref, ?string $purl, string $name = 'component'): array
{
    return array_filter(['bom-ref' => $ref, 'type' => 'library', 'name' => $name, 'version' => '1.0.0', 'purl' => $purl], fn ($value) => $value !== null);
}

function storeSbomForMergerTest(SecurityContainer $container, string $kind, string $payload, ?CarbonInterface $at = null): void
{
    $container->attachments()->create([
        'kind' => $kind,
        'mime' => 'application/json',
        'name' => "{$kind}.cdx.json",
        'payload' => $payload,
        'size_bytes' => strlen($payload),
        'created_at' => $at ?? now(),
    ]);
}

it('returns a lone sbom or sbom-archives report unchanged, and null without either', function () {
    $merger = app(ContainerSbomMerger::class);
    $fsOnly = SecurityContainer::factory()->create();
    $archivesOnly = SecurityContainer::factory()->create();
    $none = SecurityContainer::factory()->create();

    storeSbomForMergerTest($fsOnly, 'sbom', $fs = mergerCycloneDx('fs-root', [mergerComponent('a', 'pkg:maven/org.example/app@1.0.0')]));
    storeSbomForMergerTest($archivesOnly, 'sbom-archives', $archives = mergerCycloneDx('rootfs-root', [mergerComponent('b', 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3')]));

    expect($merger->forContainer($fsOnly))->toBe($fs)
        ->and($merger->forContainer($archivesOnly))->toBe($archives)
        ->and($merger->forContainer($none))->toBeNull()
        ->and($merger->hasSbom($archivesOnly))->toBeTrue()
        ->and($merger->hasSbom($none))->toBeFalse();
});

it('merges the latest report of each kind', function () {
    $container = SecurityContainer::factory()->create();

    storeSbomForMergerTest($container, 'sbom', mergerCycloneDx('old-fs', [mergerComponent('old', 'pkg:maven/org.example/old@1.0.0')]), now()->subDay());
    storeSbomForMergerTest($container, 'sbom', mergerCycloneDx('fs-root', [mergerComponent('a', 'pkg:maven/org.example/app@1.0.0')]));
    storeSbomForMergerTest($container, 'sbom-archives', mergerCycloneDx('rootfs-root', [mergerComponent('b', 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3')]));

    $merged = json_decode((string) app(ContainerSbomMerger::class)->forContainer($container), true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($merged['components'], 'purl'))->toBe(['pkg:maven/org.example/app@1.0.0', 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3'])
        ->and($merged['metadata']['component']['bom-ref'])->toBe('fs-root');
});

it('keeps the sbom entry of a component both reports list, and re-points the archive graph at it', function () {
    $fs = mergerCycloneDx('fs-root', [
        mergerComponent('fs-commons', 'pkg:maven/commons-collections/commons-collections@3.2', 'commons-collections (fs)'),
    ], [
        ['ref' => 'fs-root', 'dependsOn' => ['fs-commons']],
        ['ref' => 'fs-commons'],
    ]);
    $archives = mergerCycloneDx('rootfs-root', [
        mergerComponent('jar-commons', 'pkg:maven/commons-collections/commons-collections@3.2', 'commons-collections (jar)'),
        mergerComponent('jar-struts', 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3'),
    ], [
        ['ref' => 'rootfs-root', 'dependsOn' => ['jar-commons', 'jar-struts']],
        ['ref' => 'jar-struts', 'dependsOn' => ['jar-commons', 'missing-ref']],
    ], '1.7');

    $merged = json_decode(app(ContainerSbomMerger::class)->merge($fs, $archives), true, 512, JSON_THROW_ON_ERROR);
    $dependencies = array_column($merged['dependencies'], 'dependsOn', 'ref');

    expect($merged['components'])->toHaveCount(2)
        ->and($merged['components'][0]['name'])->toBe('commons-collections (fs)')
        ->and($merged['components'][1]['bom-ref'])->toBe('jar-struts')
        ->and(array_column($merged['dependencies'], 'ref'))->toBe(['fs-root', 'fs-commons', 'jar-struts'])
        ->and($dependencies['fs-root'])->toBe(['fs-commons', 'jar-struts'])
        ->and($dependencies['jar-struts'])->toBe(['fs-commons'])
        ->and($merged['specVersion'])->toBe('1.7')
        ->and($merged['serialNumber'])->toStartWith('urn:uuid:')
        ->and($merged['serialNumber'])->not->toBe('urn:uuid:fs-root');
});

it('renames an archive component whose bom-ref clashes with a different sbom component', function () {
    $merged = json_decode(app(ContainerSbomMerger::class)->merge(
        mergerCycloneDx('fs-root', [mergerComponent('shared-ref', 'pkg:npm/left-pad@1.3.0')]),
        mergerCycloneDx('rootfs-root', [mergerComponent('shared-ref', 'pkg:maven/org.example/lib@2.0.0')], [['ref' => 'rootfs-root', 'dependsOn' => ['shared-ref']]]),
    ), true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($merged['components'], 'bom-ref'))->toBe(['shared-ref', 'archives:shared-ref'])
        ->and($merged['dependencies'])->toBe([['ref' => 'fs-root', 'dependsOn' => ['archives:shared-ref']]]);
});

it('deduplicates components without a purl by bom-ref', function () {
    $merged = json_decode(app(ContainerSbomMerger::class)->merge(
        mergerCycloneDx('fs-root', [mergerComponent('lockfile', null, 'pom.xml')]),
        mergerCycloneDx('rootfs-root', [mergerComponent('lockfile', null, 'pom.xml'), mergerComponent('jar', null, 'app.jar')]),
    ), true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($merged['components'], 'name'))->toBe(['pom.xml', 'app.jar']);
});

it('refuses a report that is not CycloneDX', function () {
    expect(fn () => app(ContainerSbomMerger::class)->merge(mergerCycloneDx('fs-root', []), '{"runs":[]}'))
        ->toThrow(InvalidArgumentException::class, 'The SBOM is not a CycloneDX document');
});
