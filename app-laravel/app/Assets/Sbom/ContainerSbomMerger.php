<?php

declare(strict_types=1);

namespace App\Assets\Sbom;

use App\Assets\AttachmentIngestionService;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Builds a container's complete SBOM from its latest `sbom` report (`trivy fs`: manifests and
 * lock files) and its latest `sbom-archives` report (`trivy rootfs`: committed JAR/WAR/EAR
 * archives), for consumers that take a single document: the Dependency-Track upload, which
 * replaces a project's whole BOM on every upload, and the SBOM downloads.
 *
 * With only one of the two reports, its payload is returned unchanged. With both, the `sbom`
 * document is the base (top-level fields and metadata); the archive report's components are
 * added unless one with the same purl (or, without a purl, the same bom-ref) is already there,
 * and its dependency graph is folded in, with its root and duplicate components re-pointed at the
 * base's. The merged document gets a serial number of its own.
 */
final class ContainerSbomMerger
{
    private const KINDS = [AttachmentIngestionService::KIND_SBOM, AttachmentIngestionService::KIND_SBOM_ARCHIVES];

    public function hasSbom(SecurityContainer $container): bool
    {
        return $container->attachments()->whereIn('kind', self::KINDS)->exists();
    }

    /** The container's complete SBOM, or null when it has no SBOM report. */
    public function forContainer(SecurityContainer $container): ?string
    {
        $base = $this->latest($container, AttachmentIngestionService::KIND_SBOM);
        $archives = $this->latest($container, AttachmentIngestionService::KIND_SBOM_ARCHIVES);

        return match (true) {
            $base !== null && $archives !== null => $this->merge($base->payload, $archives->payload),
            default => ($base ?? $archives)?->payload,
        };
    }

    public function merge(string $basePayload, string $addedPayload): string
    {
        $base = $this->decode($basePayload);
        $added = $this->decode($addedPayload);

        $refMap = [];
        $usedRefs = $this->bomRefs($base['components'] ?? []);
        $baseRoot = $base['metadata']['component']['bom-ref'] ?? null;
        $addedRoot = $added['metadata']['component']['bom-ref'] ?? null;

        if (is_string($baseRoot) && is_string($addedRoot)) {
            $refMap[$addedRoot] = $baseRoot;
            $usedRefs[] = $baseRoot;
        }

        $base['components'] = $this->mergeComponents($this->arraysOf($base['components'] ?? []), $this->arraysOf($added['components'] ?? []), $refMap, $usedRefs);

        $known = $this->bomRefs($base['components']);

        if (is_string($baseRoot)) {
            $known[] = $baseRoot;
        }

        $dependencies = $this->mergeDependencies($this->arraysOf($base['dependencies'] ?? []), $this->arraysOf($added['dependencies'] ?? []), $refMap, $known);

        if ($dependencies !== [] || array_key_exists('dependencies', $base)) {
            $base['dependencies'] = $dependencies;
        }

        $base['specVersion'] = $this->higherSpecVersion($base['specVersion'] ?? null, $added['specVersion'] ?? null);
        $base['serialNumber'] = 'urn:uuid:' . Str::uuid();

        return json_encode($base, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function latest(SecurityContainer $container, string $kind): ?Attachment
    {
        return $container->attachments()->where('kind', $kind)->latest('created_at')->latest('id')->first();
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The SBOM is not valid JSON: ' . $exception->getMessage(), previous: $exception);
        }

        if (! is_array($data) || ($data['bomFormat'] ?? null) !== 'CycloneDX') {
            throw new InvalidArgumentException('The SBOM is not a CycloneDX document (no "bomFormat": "CycloneDX").');
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $added
     * @param  array<string, string>  $refMap  filled with added bom-ref => merged bom-ref
     * @param  list<string>  $usedRefs  every bom-ref the merged document holds
     * @return list<array<string, mixed>>
     */
    private function mergeComponents(array $base, array $added, array &$refMap, array &$usedRefs): array
    {
        $byIdentity = [];

        foreach ($base as $component) {
            $identity = $this->identity($component);

            if ($identity !== null) {
                $byIdentity[$identity] = $component['bom-ref'] ?? null;
            }
        }

        foreach ($added as $component) {
            $identity = $this->identity($component);
            $ref = $component['bom-ref'] ?? null;

            if ($identity !== null && array_key_exists($identity, $byIdentity)) {
                if (is_string($ref) && is_string($byIdentity[$identity])) {
                    $refMap[$ref] = $byIdentity[$identity];
                }

                continue;
            }

            if (is_string($ref)) {
                // Two documents' UUID bom-refs never collide in practice, but a duplicate bom-ref
                // makes the merged document invalid, so a clash is renamed rather than kept.
                $mergedRef = in_array($ref, $usedRefs, true) ? 'archives:' . $ref : $ref;
                $refMap[$ref] = $mergedRef;
                $usedRefs[] = $mergedRef;
                $component['bom-ref'] = $mergedRef;
            }

            if ($identity !== null) {
                $byIdentity[$identity] = $component['bom-ref'] ?? null;
            }

            $base[] = $component;
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $added
     * @param  array<string, string>  $refMap
     * @param  list<string>  $knownRefs  the bom-refs of the merged document's root and components
     * @return list<array{ref: string, dependsOn?: list<string>}>
     */
    private function mergeDependencies(array $base, array $added, array $refMap, array $knownRefs): array
    {
        $known = array_fill_keys($knownRefs, true);
        $graph = [];

        foreach ([[$base, []], [$added, $refMap]] as [$dependencies, $map]) {
            foreach ($dependencies as $dependency) {
                $ref = $dependency['ref'] ?? null;

                if (! is_string($ref) || ! isset($known[$map[$ref] ?? $ref])) {
                    continue;
                }

                $ref = $map[$ref] ?? $ref;
                $graph[$ref] ??= [];

                foreach ($this->listOf($dependency['dependsOn'] ?? []) as $target) {
                    if (is_string($target) && isset($known[$map[$target] ?? $target])) {
                        $graph[$ref][] = $map[$target] ?? $target;
                    }
                }
            }
        }

        $merged = [];

        foreach ($graph as $ref => $dependsOn) {
            $dependsOn = array_values(array_unique($dependsOn));
            $merged[] = $dependsOn === [] ? ['ref' => (string) $ref] : ['ref' => (string) $ref, 'dependsOn' => $dependsOn];
        }

        return $merged;
    }

    /** @param  array<string, mixed>  $component */
    private function identity(array $component): ?string
    {
        $purl = $component['purl'] ?? null;

        if (is_string($purl) && $purl !== '') {
            return 'purl:' . $purl;
        }

        $ref = $component['bom-ref'] ?? null;

        return is_string($ref) && $ref !== '' ? 'ref:' . $ref : null;
    }

    /**
     * Every bom-ref in a component list, nested components included.
     *
     * @return list<string>
     */
    private function bomRefs(mixed $components): array
    {
        $refs = [];

        foreach ($this->arraysOf($components) as $component) {
            if (is_string($component['bom-ref'] ?? null)) {
                $refs[] = $component['bom-ref'];
            }

            array_push($refs, ...$this->bomRefs($component['components'] ?? []));
        }

        return $refs;
    }

    /** @return list<mixed> */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<array<string, mixed>> */
    private function arraysOf(mixed $value): array
    {
        return array_values(array_filter($this->listOf($value), is_array(...)));
    }

    private function higherSpecVersion(mixed $base, mixed $added): mixed
    {
        if (! is_string($base) || ! is_string($added)) {
            return is_string($base) ? $base : $added;
        }

        return version_compare($added, $base, '>') ? $added : $base;
    }
}
