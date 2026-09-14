<?php

use App\Assets\Parsers\SarifArtifactUriNormalizer;

it('leaves a relative path unchanged when there is no source root', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    expect($normalizer->normalize('vendor/x/y.txt', null))->toBe('vendor/x/y.txt');
});

it('strips a file:// scratch-directory uri down to the repository-relative path', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///workspace-scratch/9c1b2d3e-aaaa-bbbb-cccc-dddddddddddd/work/Sources/A/B.cs';
    $root = '/workspace-scratch/9c1b2d3e-aaaa-bbbb-cccc-dddddddddddd/work';

    expect($normalizer->normalize($uri, $root))->toBe('Sources/A/B.cs');
});

it('only strips the file:// scheme, keeping the absolute path, when the root is null', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///workspace-scratch/9c1b2d3e-aaaa-bbbb-cccc-dddddddddddd/work/Sources/A/B.cs';

    expect($normalizer->normalize($uri, null))
        ->toBe('/workspace-scratch/9c1b2d3e-aaaa-bbbb-cccc-dddddddddddd/work/Sources/A/B.cs');
});

it('strips a source root supplied with a trailing slash the same way', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///workspace-scratch/abc/work/Sources/A/B.cs';

    expect($normalizer->normalize($uri, '/workspace-scratch/abc/work/'))->toBe('Sources/A/B.cs');
});

it('keeps the (scheme-stripped) path unchanged when it is not under the given root', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///other/path/x.cs';

    expect($normalizer->normalize($uri, '/workspace-scratch/abc/work'))->toBe('/other/path/x.cs');
});

it('percent-decodes the remainder of a file:// uri after stripping the source root', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///tmp/tmp.abc/My%20Dir/x.cs';

    expect($normalizer->normalize($uri, '/tmp/tmp.abc'))->toBe('My Dir/x.cs');
});

it('strips a leading ./ from an already-relative path', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    expect($normalizer->normalize('./src/x.js', null))->toBe('src/x.js');
});

it('normalizes a Windows file:// uri and drive-letter source root', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///C:/work/src/x.cs';

    expect($normalizer->normalize($uri, 'C:\\work'))->toBe('src/x.cs');
});

it('falls back to the original uri unchanged when stripping the root would produce an empty path', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    $uri = 'file:///workspace-scratch/abc/work/';

    expect($normalizer->normalize($uri, '/workspace-scratch/abc/work'))->toBe($uri);
});

it('never resolves uriBaseId-relative Trivy paths, since it is never given one to resolve against', function () {
    $normalizer = new SarifArtifactUriNormalizer;

    // Trivy's own paths are already relative ("vendor/x/y.txt" with uriBaseId ROOTPATH);
    // the normalizer is only ever called with the *scanned-tree* source_root, never with
    // originalUriBaseIds.ROOTPATH.uri, so a value that happens to look like it is just
    // another root that doesn't match and leaves the path untouched.
    expect($normalizer->normalize('vendor/x/y.txt', '/scan'))->toBe('vendor/x/y.txt');
});
