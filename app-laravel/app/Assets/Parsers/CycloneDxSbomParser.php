<?php

namespace App\Assets\Parsers;

use JsonException;

/**
 * Parses a CycloneDX SBOM (as produced by `trivy fs --format cyclonedx`) into
 * ParsedComponent entries. Most non-purl components are scan-artifact
 * descriptors (e.g. the lockfile itself) rather than actual dependencies, and
 * are skipped. Components of type operating-system/platform/framework carry
 * real identity (e.g. the installed .NET runtime/SDK) but package managers
 * never assign them a purl, so a stable pkg:generic purl is synthesized for
 * them instead of dropping them.
 */
final class CycloneDxSbomParser
{
    private const PURL_LESS_TYPES = ['operating-system', 'platform', 'framework'];

    /**
     * @return list<ParsedComponent>
     */
    public function parse(string $payload): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $components = $data['components'] ?? null;

        if (! is_array($components)) {
            return [];
        }

        $parsed = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $name = $component['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $version = is_string($component['version'] ?? null) ? $component['version'] : null;
            $purl = $component['purl'] ?? null;
            $type = $component['type'] ?? null;

            if (is_string($purl) && $purl !== '') {
                $ecosystem = $this->ecosystemFromPurl($purl);
            } elseif (is_string($type) && in_array($type, self::PURL_LESS_TYPES, true)) {
                $purl = $this->syntheticPurl($name, $version);
                $ecosystem = $type;
            } else {
                continue;
            }

            $parsed[] = new ParsedComponent(
                name: $name,
                version: $version,
                ecosystem: $ecosystem,
                purl: $purl,
                license: $this->firstLicense($component),
                metadata: [
                    'bomRef' => $component['bom-ref'] ?? null,
                    'type' => $type,
                    'properties' => $component['properties'] ?? [],
                ],
            );
        }

        return $parsed;
    }

    private function syntheticPurl(string $name, ?string $version): string
    {
        $purl = 'pkg:generic/' . rawurlencode($name);

        return $version !== null ? $purl . '@' . rawurlencode($version) : $purl;
    }

    private function ecosystemFromPurl(string $purl): ?string
    {
        if (! str_starts_with($purl, 'pkg:')) {
            return null;
        }

        $rest = substr($purl, 4);
        $slashPosition = strpos($rest, '/');

        if ($slashPosition === false) {
            return null;
        }

        $type = substr($rest, 0, $slashPosition);

        return $type !== '' ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function firstLicense(array $component): ?string
    {
        $licenses = $component['licenses'] ?? null;

        if (! is_array($licenses) || $licenses === []) {
            return null;
        }

        $first = $licenses[0]['license'] ?? null;

        if (! is_array($first)) {
            return null;
        }

        $id = $first['id'] ?? $first['name'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
