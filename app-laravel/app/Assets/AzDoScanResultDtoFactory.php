<?php

declare(strict_types=1);

namespace App\Assets;

use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\AzDo\AzDoProject;
use App\Sources\AzDo\AzDoRepository;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

/**
 * Builds the SystemDto/ContainerDto for one `invoke-ops.ps1 -SbomScan` /
 * `-StaticAnalysis` run.jsonl line by feeding the fields the scan already
 * collected from Azure DevOps (project id/name/description/url, repository
 * id/name/webUrl/remoteUrl/defaultBranch) through the very same AzDoNormalizer
 * a live source sync uses.
 *
 * Reusing AzDoNormalizer here — rather than re-deriving web URLs, branch
 * normalization and SourceContextFacts keys by hand — describes the
 * SoftwareSystem/SecurityContainer identically to a source sync, so both flows
 * converge on byte-for-byte the same metadata.
 *
 * The scan writes the repository's remote (clone) URL under the `webUrl` key and
 * its browser URL under `repositoryWebUrl`; both are mapped to the AzDoRepository
 * fields the normalizer expects. A missing field is treated as absent (null),
 * never as an empty string, so no blank facts are stored.
 */
final class AzDoScanResultDtoFactory
{
    /** @param array<string, mixed> $result */
    public static function system(array $result): SystemDto
    {
        return AzDoNormalizer::toSystem(AzDoProject::fromArray([
            'id' => (string) ($result['projectId'] ?? ''),
            'name' => (string) ($result['project'] ?? ''),
            'description' => self::stringOrNull($result['projectDescription'] ?? null),
            'url' => self::stringOrNull($result['projectUrl'] ?? null),
        ]));
    }

    /** @param array<string, mixed> $result */
    public static function container(array $result): ContainerDto
    {
        return AzDoNormalizer::toContainer(AzDoRepository::fromArray([
            'id' => (string) ($result['repositoryId'] ?? ''),
            'name' => (string) ($result['repository'] ?? ''),
            'project' => [
                'id' => (string) ($result['projectId'] ?? ''),
                'name' => (string) ($result['project'] ?? ''),
            ],
            'defaultBranch' => self::stringOrNull($result['defaultBranch'] ?? null),
            'remoteUrl' => self::stringOrNull($result['webUrl'] ?? null),
            'webUrl' => self::stringOrNull($result['repositoryWebUrl'] ?? null),
        ]));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
