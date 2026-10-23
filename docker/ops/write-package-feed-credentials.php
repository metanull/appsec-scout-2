<?php

// Writes NuGet and Maven credentials for the Azure DevOps organization's own package feeds
// into <home>, for the repository cloned at <workdir>, so `dotnet restore` and Maven builds in
// collect-sboms.sh/collect-static-analysis.sh can authenticate to them. Neither tool reads the
// .netrc/.git-credentials git uses, and both look credentials up by the name a repository gives
// the feed (a nuget.config source key, a pom.xml repository id), so the clone's own files are
// read to find those names. A name is credentialed only when every definition of it in the
// clone points at the organization's feed host, so the PAT is never sent to another host.
//
// Mirrors App\SourceControl\Collection\PackageFeedCredentialWriter (the in-app static analysis
// job); keep the two in step. This image carries no app code, hence the standalone copy.
//
// Usage: php write-package-feed-credentials.php <workdir> <home>
// Environment: AZDO_ORG, AZDO_PAT (read from the environment, never from argv).
// Prints one JSON object: {"nugetSources": [...], "mavenRepositories": [...],
// "mavenSettingsPath": "<home>/.m2/settings.xml" | null}. Names only, never the PAT.

declare(strict_types=1);

const USERNAME = 'azdo';

function isOrganizationFeed(string $url, string $organization): bool
{
    $parts = parse_url(trim($url));

    if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
        return false;
    }

    $host = strtolower($parts['host'] ?? '');
    $organization = strtolower($organization);

    if ($host === $organization . '.pkgs.visualstudio.com') {
        return true;
    }

    $firstSegment = explode('/', ltrim($parts['path'] ?? '', '/'), 2)[0];

    return $host === 'pkgs.dev.azure.com' && strtolower(rawurldecode($firstSegment)) === $organization;
}

// NuGet stores a source's credentials under an element named after the source key, encoded the
// way .NET's XmlConvert.EncodeLocalName does.
function encodeXmlName(string $name): string
{
    $chars = mb_str_split($name);
    $encoded = '';

    foreach ($chars as $index => $char) {
        $valid = $index === 0
            ? (ctype_alpha($char) || $char === '_')
            : (ctype_alnum($char) || in_array($char, ['_', '-', '.'], true));

        $escapesSequence = $char === '_' && ($chars[$index + 1] ?? '') === 'x';

        if ($valid && ! $escapesSequence) {
            $encoded .= $char;

            continue;
        }

        $codePoint = mb_ord($char);
        $encoded .= sprintf($codePoint > 0xFFFF ? '_x%08X_' : '_x%04X_', $codePoint);
    }

    return $encoded;
}

/** @return list<string> */
function findFiles(string $workDir, callable $matches): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($workDir, FilesystemIterator::SKIP_DOTS),
            // Same scope as the in-app job's Symfony Finder defaults: dot-directories (.git) are skipped.
            fn (SplFileInfo $file): bool => ! str_starts_with($file->getFilename(), '.'),
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD,
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $matches($file->getFilename())) {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

// A file that is not well-formed XML simply contributes no names; the build reports it.
function loadXml(string $path): ?DOMXPath
{
    $contents = (string) @file_get_contents($path);

    if (trim($contents) === '') {
        return null;
    }

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($contents, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $loaded ? new DOMXPath($document) : null;
}

/** @return array<string, list<string>> */
function nugetSources(string $workDir): array
{
    $sources = [];

    foreach (findFiles($workDir, fn (string $name): bool => strtolower($name) === 'nuget.config') as $path) {
        $xpath = loadXml($path);

        foreach ($xpath?->query('/configuration/packageSources/add') ?: [] as $add) {
            if ($add instanceof DOMElement && $add->getAttribute('key') !== '') {
                $sources[$add->getAttribute('key')][] = $add->getAttribute('value');
            }
        }
    }

    return $sources;
}

/** @return array<string, list<string>> */
function mavenRepositories(string $workDir): array
{
    $repositories = [];
    $query = "//*[local-name()='repository' or local-name()='pluginRepository' or local-name()='snapshotRepository']";

    foreach (findFiles($workDir, fn (string $name): bool => $name === 'pom.xml') as $path) {
        $xpath = loadXml($path);

        foreach ($xpath?->query($query) ?: [] as $repository) {
            if (! $repository instanceof DOMElement) {
                continue;
            }

            $id = trim((string) $xpath->evaluate("string(*[local-name()='id'])", $repository));
            $url = trim((string) $xpath->evaluate("string(*[local-name()='url'])", $repository));

            if ($id !== '' && $url !== '') {
                $repositories[$id][] = $url;
            }
        }
    }

    return $repositories;
}

/**
 * @param  array<string, list<string>>  $definitions
 * @return list<string>
 */
function organizationNames(array $definitions, string $organization): array
{
    $names = [];

    foreach ($definitions as $name => $urls) {
        if (array_filter($urls, fn (string $url): bool => ! isOrganizationFeed($url, $organization)) === []) {
            $names[] = (string) $name;
        }
    }

    sort($names);

    return $names;
}

function putPrivate(string $path, string $contents): void
{
    if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException('Could not create ' . dirname($path));
    }

    if (file_put_contents($path, $contents) === false || ! chmod($path, 0600)) {
        throw new RuntimeException('Could not write ' . $path);
    }
}

/** @param list<string> $sources */
function writeNugetConfig(string $home, array $sources, string $pat): void
{
    $document = new DOMDocument('1.0', 'utf-8');
    $document->formatOutput = true;
    $credentials = $document->createElement('packageSourceCredentials');
    $document->appendChild($document->createElement('configuration'))->appendChild($credentials);

    foreach ($sources as $source) {
        $entry = $credentials->appendChild($document->createElement(encodeXmlName($source)));

        foreach (['Username' => USERNAME, 'ClearTextPassword' => $pat] as $key => $value) {
            $add = $document->createElement('add');
            $add->setAttribute('key', $key);
            $add->setAttribute('value', $value);
            $entry->appendChild($add);
        }
    }

    putPrivate($home . '/.nuget/NuGet/NuGet.Config', (string) $document->saveXML());
}

/** @param list<string> $repositories */
function writeMavenSettings(string $home, array $repositories, string $pat): string
{
    $namespace = 'http://maven.apache.org/SETTINGS/1.2.0';
    $document = new DOMDocument('1.0', 'utf-8');
    $document->formatOutput = true;
    $servers = $document->createElementNS($namespace, 'servers');
    $document->appendChild($document->createElementNS($namespace, 'settings'))->appendChild($servers);

    foreach ($repositories as $repository) {
        $server = $servers->appendChild($document->createElementNS($namespace, 'server'));

        foreach (['id' => $repository, 'username' => USERNAME, 'password' => $pat] as $field => $value) {
            $element = $document->createElementNS($namespace, $field);
            $element->appendChild($document->createTextNode($value));
            $server->appendChild($element);
        }
    }

    $path = $home . '/.m2/settings.xml';
    putPrivate($path, (string) $document->saveXML());

    return $path;
}

[, $workDir, $home] = $argv + [null, '', ''];
$organization = (string) getenv('AZDO_ORG');
$pat = (string) getenv('AZDO_PAT');

if ($workDir === '' || $home === '' || ! is_dir($workDir) || ! is_dir($home) || $organization === '' || $pat === '') {
    fwrite(STDERR, "Usage: AZDO_ORG=... AZDO_PAT=... php write-package-feed-credentials.php <workdir> <home>\n");
    exit(2);
}

$nuget = organizationNames(nugetSources($workDir), $organization);
$maven = organizationNames(mavenRepositories($workDir), $organization);

if ($nuget !== []) {
    writeNugetConfig($home, $nuget, $pat);
}

echo json_encode([
    'nugetSources' => $nuget,
    'mavenRepositories' => $maven,
    'mavenSettingsPath' => $maven !== [] ? writeMavenSettings($home, $maven, $pat) : null,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
