<?php

namespace App\Sources\AzDo;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\SecurityEvents\SourceLinkHelper;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;

final class AzDoNormalizer
{
    public const SOURCE_ID = 'azdo';

    public static function toSystem(AzDoProject $project): SystemDto
    {
        $webUrl = self::buildProjectWebUrl($project);
        $metadata = [];

        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_PROJECT_ID, $project->id);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_PROJECT_NAME, $project->name);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_PROJECT_WEB_URL, $webUrl);

        return new SystemDto(
            sourceSystemId: $project->id,
            name: $project->name,
            description: $project->description,
            url: $webUrl,
            metadata: $metadata,
        );
    }

    public static function toContainer(AzDoRepository $repo): ContainerDto
    {
        $metadata = [];

        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_REPOSITORY_ID, $repo->id);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_REPOSITORY_NAME, $repo->name);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_REPOSITORY_WEB_URL, $repo->webUrl);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL, $repo->remoteUrl);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH, self::normalizeBranch($repo->defaultBranch));
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::SOURCE_PROVIDER, 'azure-repos');

        return new ContainerDto(
            sourceContainerId: $repo->id,
            name: $repo->name,
            sourceSystemId: '',
            kind: 'repository',
            url: $repo->webUrl,
            metadata: $metadata,
        );
    }

    public static function toEvent(
        AzDoAlert $alert,
        string $repoId = '',
        ?AzDoProject $project = null,
        ?AzDoRepository $repo = null,
    ): EventDto {
        $type = self::mapType($alert);
        $rule = self::extractRule($alert);
        $location = self::extractLocation($alert);
        $alertWebUrl = self::buildAlertWebUrl($alert, $project, $repo);
        $metadata = self::buildMetadata($alert, $project, $repo, $location, $alertWebUrl);

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
            url: $alertWebUrl,
            remediation: $rule['helpMessage'] ?? null,
            filePath: $location['filePath'] ?? null,
            startLine: isset($location['region']['startLine']) ? (int) $location['region']['startLine'] : null,
            endLine: isset($location['region']['endLine']) ? (int) $location['region']['endLine'] : null,
            snippet: $location['region']['snippet']['text'] ?? null,
            commitSha: $location['versionControl']['commitHash'] ?? null,
            branch: $location['versionControl']['branch'] ?? self::normalizeBranch($repo?->defaultBranch),
            versionControlUrl: $location['versionControl']['itemUrl'] ?? null,
            metadata: $metadata,
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
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private static function buildMetadata(
        AzDoAlert $alert,
        ?AzDoProject $project,
        ?AzDoRepository $repo,
        array $location,
        ?string $alertWebUrl,
    ): array {
        $meta = [];

        $meta = SourceContextFacts::set($meta, SourceContextFacts::SOURCE_ALERT_WEB_URL, $alertWebUrl);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::AZDO_PROJECT_ID, $project?->id);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::AZDO_PROJECT_NAME, $project?->name);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::AZDO_REPOSITORY_ID, $repo?->id);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::AZDO_REPOSITORY_NAME, $repo?->name);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::CODE_COMMIT_SHA, $location['versionControl']['commitHash'] ?? null);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::CODE_DEFAULT_BRANCH, self::normalizeBranch($location['versionControl']['branch'] ?? $repo?->defaultBranch));
        $meta = SourceContextFacts::set($meta, SourceContextFacts::CODE_FILE_PATH, $location['filePath'] ?? null);
        $meta = SourceContextFacts::set($meta, SourceContextFacts::AZDO_ALERT_TYPE, $alert->alertType);

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

                $cweId = self::extractCweFromRule($rule);
                if ($cweId !== null) {
                    $meta = SourceContextFacts::set($meta, SourceContextFacts::SECURITY_CWE, $cweId);
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

                $meta = SourceContextFacts::set($meta, SourceContextFacts::PACKAGE_NAME, $alert->additionalData['packageName']);
                $meta = SourceContextFacts::set($meta, SourceContextFacts::PACKAGE_VERSION, $alert->additionalData['packageVersion'] ?? null);
                $meta = SourceContextFacts::set($meta, SourceContextFacts::PACKAGE_ECOSYSTEM, $alert->additionalData['ecosystem'] ?? null);
            }

            if (isset($alert->additionalData['cveId'])) {
                $meta['cve'] = $alert->additionalData['cveId'];
                $meta = SourceContextFacts::set($meta, SourceContextFacts::SECURITY_CVE, $alert->additionalData['cveId']);
            }
        }

        if ($alert->logicalLocations !== []) {
            $meta['logicalLocations'] = $alert->logicalLocations;
        }

        // Build links array for EventLinkCatalog
        $links = [];

        if (is_string($alertWebUrl) && SourceLinkHelper::isSafeUrl($alertWebUrl)) {
            $links[] = ['label' => 'Source alert', 'url' => $alertWebUrl];
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

    private static function buildAlertWebUrl(
        AzDoAlert $alert,
        ?AzDoProject $project,
        ?AzDoRepository $repo,
    ): ?string {
        if ($alert->alertUri !== null && SourceLinkHelper::isSafeUrl($alert->alertUri)) {
            return $alert->alertUri;
        }

        if ($repo?->webUrl !== null && SourceLinkHelper::isSafeUrl($repo->webUrl)) {
            return rtrim($repo->webUrl, '/') . '/alerts/' . $alert->alertId;
        }

        if ($repo?->apiUrl === null || $project?->name === null) {
            return null;
        }

        $parts = explode('/_apis/', $repo->apiUrl, 2);
        $orgUrl = $parts[0];

        if (! SourceLinkHelper::isSafeUrl($orgUrl) || $repo->name === '') {
            return null;
        }

        return rtrim($orgUrl, '/')
            . '/'
            . rawurlencode($project->name)
            . '/_git/'
            . rawurlencode($repo->name)
            . '/alerts/'
            . $alert->alertId;
    }

    private static function buildProjectWebUrl(AzDoProject $project): ?string
    {
        if ($project->url === null || $project->url === '') {
            return null;
        }

        return str_replace('/_apis/projects/', '/', $project->url);
    }

    private static function normalizeBranch(?string $branch): ?string
    {
        if (! is_string($branch) || $branch === '') {
            return null;
        }

        return str_starts_with($branch, 'refs/heads/')
            ? substr($branch, strlen('refs/heads/'))
            : $branch;
    }

    /** @param array<string, mixed> $rule */
    private static function extractCweFromRule(array $rule): ?string
    {
        $candidates = [
            $rule['id'] ?? null,
            $rule['opaqueId'] ?? null,
            $rule['friendlyName'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            if (preg_match('/CWE-?(\d+)/i', $candidate, $matches) === 1) {
                return 'CWE-' . $matches[1];
            }
        }

        return null;
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
