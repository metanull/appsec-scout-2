<?php

namespace App\SourceControl\Collection;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Lets `dotnet restore` and Maven authenticate to the organization's own
 * Azure Artifacts feeds with the AzDO Repos PAT. Neither tool reads the
 * `.netrc`/`.git-credentials` git uses, and both look credentials up by the
 * name a repository gives the feed (a nuget.config source key, a pom.xml
 * repository id), so the clone's own files are read to find those names.
 *
 * Credentials are written only for names that point at the organization's
 * feed host in every file defining them, so the PAT is never handed to any
 * other host. Both files land in the job's own scratch HOME, which the job
 * deletes on every exit path.
 *
 * docker/ops/write-package-feed-credentials.php mirrors this for the host-side
 * Ops scripts; keep the two in step.
 */
final class PackageFeedCredentialWriter
{
    public const USERNAME = 'azdo';

    public function write(string $workDir, string $homeDir, string $organization, string $pat): PackageFeedCredentials
    {
        $nugetSources = $this->organizationNames($this->nugetSources($workDir), $organization);
        $mavenRepositories = $this->organizationNames($this->mavenRepositories($workDir), $organization);

        if ($nugetSources !== []) {
            $this->writeNugetConfig($homeDir, $nugetSources, $pat);
        }

        $mavenSettingsPath = $mavenRepositories !== []
            ? $this->writeMavenSettings($homeDir, $mavenRepositories, $pat)
            : null;

        return new PackageFeedCredentials($nugetSources, $mavenRepositories, $mavenSettingsPath);
    }

    /**
     * True for `https://pkgs.dev.azure.com/{organization}/...` and the legacy
     * `https://{organization}.pkgs.visualstudio.com/...`, case-insensitively.
     */
    public static function isOrganizationFeed(string $url, string $organization): bool
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

    /**
     * NuGet stores a source's credentials under an element named after the
     * source key, encoded the way .NET's XmlConvert.EncodeLocalName does
     * (NuGet decodes the element name when it reads the file back).
     */
    public static function encodeXmlName(string $name): string
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

    /**
     * Every name defined anywhere in the clone, with every URL it is given.
     *
     * @return array<string, list<string>>
     */
    private function nugetSources(string $workDir): array
    {
        $sources = [];

        foreach ($this->files($workDir, '/^nuget\.config$/i') as $path) {
            $xpath = $this->load($path);

            if ($xpath === null) {
                continue;
            }

            foreach ($xpath->query('/configuration/packageSources/add') ?: [] as $add) {
                if ($add instanceof DOMElement && $add->getAttribute('key') !== '') {
                    $sources[$add->getAttribute('key')][] = $add->getAttribute('value');
                }
            }
        }

        return $sources;
    }

    /** @return array<string, list<string>> */
    private function mavenRepositories(string $workDir): array
    {
        $repositories = [];
        $query = "//*[local-name()='repository' or local-name()='pluginRepository' or local-name()='snapshotRepository']";

        foreach ($this->files($workDir, 'pom.xml') as $path) {
            $xpath = $this->load($path);

            if ($xpath === null) {
                continue;
            }

            foreach ($xpath->query($query) ?: [] as $repository) {
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
     * Names whose every definition points at the organization's feed. A name
     * that also points elsewhere is left out: the tool would send the
     * credential to whichever definition wins for a given project.
     *
     * @param  array<string, list<string>>  $definitions
     * @return list<string>
     */
    private function organizationNames(array $definitions, string $organization): array
    {
        $names = [];

        foreach ($definitions as $name => $urls) {
            $allOrganizationFeeds = array_filter($urls, fn (string $url): bool => ! self::isOrganizationFeed($url, $organization)) === [];

            if ($allOrganizationFeeds) {
                $names[] = (string) $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @param list<string> $sources */
    private function writeNugetConfig(string $homeDir, array $sources, string $pat): void
    {
        $document = new DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;
        $credentials = $document->createElement('packageSourceCredentials');
        $document->appendChild($document->createElement('configuration'))->appendChild($credentials);

        foreach ($sources as $source) {
            $entry = $credentials->appendChild($document->createElement(self::encodeXmlName($source)));
            $entry->appendChild($this->nugetSetting($document, 'Username', self::USERNAME));
            $entry->appendChild($this->nugetSetting($document, 'ClearTextPassword', $pat));
        }

        $this->putPrivate($homeDir . '/.nuget/NuGet/NuGet.Config', (string) $document->saveXML());
    }

    private function nugetSetting(DOMDocument $document, string $key, string $value): DOMElement
    {
        $add = $document->createElement('add');
        $add->setAttribute('key', $key);
        $add->setAttribute('value', $value);

        return $add;
    }

    /** @param list<string> $repositories */
    private function writeMavenSettings(string $homeDir, array $repositories, string $pat): string
    {
        $namespace = 'http://maven.apache.org/SETTINGS/1.2.0';
        $document = new DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;
        $servers = $document->createElementNS($namespace, 'servers');
        $document->appendChild($document->createElementNS($namespace, 'settings'))->appendChild($servers);

        foreach ($repositories as $repository) {
            $server = $servers->appendChild($document->createElementNS($namespace, 'server'));

            foreach (['id' => $repository, 'username' => self::USERNAME, 'password' => $pat] as $field => $value) {
                $element = $document->createElementNS($namespace, $field);
                $element->appendChild($document->createTextNode($value));
                $server->appendChild($element);
            }
        }

        $path = $homeDir . '/.m2/settings.xml';
        $this->putPrivate($path, (string) $document->saveXML());

        return $path;
    }

    private function putPrivate(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        File::put($path, $contents);
        chmod($path, 0600);
    }

    /** @return list<string> */
    private function files(string $workDir, string $name): array
    {
        if (! File::isDirectory($workDir)) {
            return [];
        }

        $paths = [];

        foreach ((new Finder)->files()->in($workDir)->ignoreUnreadableDirs()->name($name) as $file) {
            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /**
     * A file that is not well-formed XML is the repository's own problem —
     * the build reports it — so it simply contributes no names.
     */
    private function load(string $path): ?DOMXPath
    {
        $contents = File::get($path);

        if (trim($contents) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? new DOMXPath($document) : null;
    }
}
