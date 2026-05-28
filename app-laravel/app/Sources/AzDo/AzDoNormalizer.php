<?php

namespace App\Sources\AzDo;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\SecurityEvents\SourceLinkHelper;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;

final class AzDoNormalizer
{
    public const SOURCE_ID = 'azdo';

    public static function toSystem(AzDoProject $project): SystemDto
    {
        return new SystemDto(
            sourceSystemId: $project->id,
            name: $project->name,
            description: $project->description,
            url: $project->url,
        );
    }

    public static function toContainer(AzDoRepository $repo): ContainerDto
    {
        return new ContainerDto(
            sourceContainerId: $repo->id,
            name: $repo->name,
            sourceSystemId: '',
            kind: 'repository',
            url: $repo->webUrl,
        );
    }

    public static function toEvent(AzDoAlert $alert, string $repoId = ''): EventDto
    {
        $type = self::mapType($alert);
        $rule = self::extractRule($alert);
        $location = self::extractLocation($alert);

        return new EventDto(
            sourceEventId: (string) $alert->alertId,
            sourceSystemId: '',
            title: $alert->title,
            severity: self::mapSeverity($alert->severity),
            state: self::mapState($alert->state),
            type: $type,
            description: self::buildDescription($alert, $rule),
            ruleId: $rule['opaqueId'] ?? $rule['id'] ?? null,
            fingerprint: self::buildFingerprint($alert, $repoId),
            url: $alert->alertUri,
            remediation: $rule['helpMessage'] ?? null,
            filePath: $location['filePath'] ?? null,
            startLine: isset($location['region']['startLine']) ? (int) $location['region']['startLine'] : null,
            endLine: isset($location['region']['endLine']) ? (int) $location['region']['endLine'] : null,
            snippet: $location['region']['snippet']['text'] ?? null,
            commitSha: $location['versionControl']['commitHash'] ?? null,
            branch: $location['versionControl']['branch'] ?? null,
            versionControlUrl: $location['versionControl']['itemUrl'] ?? null,
            metadata: self::buildMetadata($alert),
            firstSeenAt: $alert->firstSeenDate !== null ? new \DateTime($alert->firstSeenDate) : null,
            lastSeenAt: $alert->lastSeenDate !== null ? new \DateTime($alert->lastSeenDate) : null,
        );
    }

    public static function mapSeverity(string $severity): EventSeverity
    {
        return match (strtolower($severity)) {
            'critical' => EventSeverity::Critical,
            'high', 'error' => EventSeverity::High,
            'medium', 'warning' => EventSeverity::Medium,
            'low' => EventSeverity::Low,
            'note', 'informational' => EventSeverity::Informational,
            default => EventSeverity::Medium,
        };
    }

    public static function mapState(string $state): EventState
    {
        return match (strtolower($state)) {
            'active' => EventState::Open,
            'dismissed', 'autodismissed' => EventState::Dismissed,
            'fixed' => EventState::Resolved,
            default => EventState::Open,
        };
    }

    private static function mapType(AzDoAlert $alert): EventType
    {
        return match ($alert->alertType) {
            'dependency' => EventType::Dependency,
            'secret' => EventType::Secret,
            'license' => EventType::License,
            'code' => self::mapCodeAlertType($alert),
            default => EventType::Vulnerability,
        };
    }

    private static function mapCodeAlertType(AzDoAlert $alert): EventType
    {
        foreach ($alert->tools as $tool) {
            $toolName = strtolower((string) ($tool['name'] ?? ''));
            if (str_contains($toolName, 'eslint') || str_contains($toolName, 'quality') || str_contains($toolName, 'sonar')) {
                return EventType::CodeQuality;
            }
        }

        return EventType::Vulnerability;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractRule(AzDoAlert $alert): array
    {
        foreach ($alert->tools as $tool) {
            if (isset($tool['rules'][0])) {
                return (array) $tool['rules'][0];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractLocation(AzDoAlert $alert): array
    {
        if ($alert->physicalLocations !== []) {
            return (array) $alert->physicalLocations[0];
        }

        if ($alert->logicalLocations !== []) {
            $loc = $alert->logicalLocations[0];

            return ['filePath' => $loc['fullyQualifiedName'] ?? null];
        }

        return [];
    }

    private static function buildFingerprint(AzDoAlert $alert, string $repoId): ?string
    {
        if ($alert->validationFingerprints !== []) {
            $vf = $alert->validationFingerprints[0];

            return $vf['validationFingerprintHash'] ?? null;
        }

        if ($alert->alertType === 'dependency') {
            $name = $alert->additionalData['packageName'] ?? null;
            $rule = self::extractRule($alert);
            $ruleId = $rule['id'] ?? null;

            if ($name !== null && $ruleId !== null) {
                return sha1("{$repoId}|{$name}|{$ruleId}");
            }
        }

        $ruleId = (self::extractRule($alert))['opaqueId'] ?? null;
        $loc = $alert->physicalLocations[0] ?? null;
        $filePath = $loc['filePath'] ?? null;

        if ($ruleId !== null && $filePath !== null) {
            $line = $loc['region']['startLine'] ?? 0;

            return "{$ruleId}:{$filePath}:{$line}";
        }

        return null;
    }

    /** @param array<string, mixed>|null $rule */
    private static function buildDescription(AzDoAlert $alert, ?array $rule): ?string
    {
        $parts = [];

        if (isset($rule['description']) && $rule['description'] !== '') {
            $parts[] = $rule['description'];
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildMetadata(AzDoAlert $alert): array
    {
        $meta = [];

        if ($alert->truncatedSecret !== null) {
            $meta['truncatedSecret'] = $alert->truncatedSecret;
        }

        if ($alert->validationFingerprints !== []) {
            $meta['validationFingerprints'] = $alert->validationFingerprints;
        }

        if ($alert->tools !== []) {
            $tool = $alert->tools[0];
            $meta['detector'] = $tool['name'] ?? null;

            // Preserve rule help URI for documentation links
            $rule = $tool['rules'][0] ?? null;
            if (is_array($rule)) {
                $helpUri = $rule['helpUri'] ?? $rule['help']['text'] ?? null;
                if (is_string($helpUri) && SourceLinkHelper::isSafeUrl($helpUri)) {
                    $meta['ruleHelpUri'] = $helpUri;
                }
            }
        }

        if ($alert->additionalData !== null) {
            if (isset($alert->additionalData['packageName'])) {
                $meta['package'] = [
                    'name' => $alert->additionalData['packageName'],
                    'version' => $alert->additionalData['packageVersion'] ?? null,
                    'ecosystem' => $alert->additionalData['ecosystem'] ?? null,
                ];
            }

            if (isset($alert->additionalData['cveId'])) {
                $meta['cve'] = $alert->additionalData['cveId'];
            }
        }

        if ($alert->logicalLocations !== []) {
            $meta['logicalLocations'] = $alert->logicalLocations;
        }

        // Build links array for EventLinkCatalog
        $links = [];

        if ($alert->alertUri !== null && SourceLinkHelper::isSafeUrl($alert->alertUri)) {
            $links[] = ['label' => 'Source alert', 'url' => $alert->alertUri];
        }

        // Item URL from physical locations (version control link to source file)
        foreach ($alert->physicalLocations as $loc) {
            $itemUrl = $loc['versionControl']['itemUrl'] ?? null;
            if (is_string($itemUrl) && SourceLinkHelper::isSafeUrl($itemUrl)) {
                $links[] = ['label' => 'Source file', 'url' => $itemUrl];
                break;
            }
        }

        // Rule help URI
        if (isset($meta['ruleHelpUri'])) {
            $links[] = ['label' => 'Rule documentation', 'url' => $meta['ruleHelpUri']];
        }

        // CVE link
        $cveId = $meta['cve'] ?? null;
        if (is_string($cveId)) {
            $cveUrl = SourceLinkHelper::cveLinkUrl($cveId);
            if ($cveUrl !== null) {
                $links[] = ['label' => 'CVE: ' . strtoupper($cveId), 'url' => $cveUrl];
            }
        }

        if ($links !== []) {
            $meta['links'] = $links;
        }

        return $meta ?: [];
    }

    /**
     * Map local EventState to the AzDO upstream state update payload.
     *
     * @return array<string, string>
     */
    public static function mapStateToSource(EventState $state, ?string $dismissalReason = null, ?string $dismissalMessage = null): array
    {
        return match ($state) {
            EventState::Resolved => ['state' => 'fixed'],
            EventState::Dismissed => [
                'state' => 'dismissed',
                'dismissalReason' => $dismissalReason ?? 'falsePositive',
                'dismissalMessage' => $dismissalMessage ?? 'Dismissed by AppSec Scout',
            ],
            default => ['state' => 'active'],
        };
    }
}
