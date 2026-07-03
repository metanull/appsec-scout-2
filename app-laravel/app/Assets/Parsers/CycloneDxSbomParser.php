<?php

namespace App\Assets\Parsers;

use JsonException;

/**
 * Parses a CycloneDX SBOM (as produced by `trivy fs --format cyclonedx`) into
 * ParsedComponent entries. Components without a purl are scan-artifact
 * descriptors (e.g. the lockfile itself) rather than actual dependencies, and
 * are skipped.
 */
final class CycloneDxSbomParser
{
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

            $purl = $component['purl'] ?? null;
            $name = $component['name'] ?? null;

            if (! is_string($purl) || $purl === '' || ! is_string($name) || $name === '') {
                continue;
            }

            $parsed[] = new ParsedComponent(
                name: $name,
                version: is_string($component['version'] ?? null) ? $component['version'] : null,
                ecosystem: $this->ecosystemFromPurl($purl),
                purl: $purl,
                license: $this->firstLicense($component),
                metadata: [
                    'bomRef' => $component['bom-ref'] ?? null,
                    'type' => $component['type'] ?? null,
                    'properties' => $component['properties'] ?? [],
                ],
            );
        }

        return $parsed;
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
