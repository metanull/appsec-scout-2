<?php

namespace App\Sources\Asoc;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\SecurityEvents\SourceLinkHelper;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;

final class AsocNormalizer
{
    public const SOURCE_ID = 'asoc';

    /** @param array<string, mixed> $application */
    public static function toSystem(array $application): SystemDto
    {
        $appId = (string) ($application['Id'] ?? $application['id'] ?? '');
        $appName = (string) ($application['Name'] ?? $application['name'] ?? 'Unknown App');

        $metadata = $application;
        $metadata['asoc']['application']['id'] = $appId;
        $metadata['asoc']['application']['name'] = $appName;

        return new SystemDto(
            sourceSystemId: $appId,
            name: $appName,
            description: self::toNullableString($application['Description'] ?? $application['description'] ?? null),
            url: self::toNullableString($application['Url'] ?? $application['url'] ?? null),
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $scan */
    public static function toContainer(array $scan, string $sourceSystemId): ContainerDto
    {
        $scanId = (string) ($scan['Id'] ?? $scan['id'] ?? '');
        $scanName = (string) ($scan['Name'] ?? $scan['name'] ?? 'Unnamed Scan');

        $metadata = $scan;
        $metadata['asoc']['scan']['id'] = $scanId;
        $metadata['asoc']['scan']['name'] = $scanName;

        return new ContainerDto(
            sourceContainerId: $scanId,
            name: $scanName,
            sourceSystemId: $sourceSystemId,
            kind: 'scan',
            url: null,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $issue */
    public static function toEvent(array $issue, string $sourceSystemId): EventDto
    {
        $metadata = self::buildMetadata($issue);

        return new EventDto(
            sourceEventId: (string) ($issue['Id'] ?? $issue['id'] ?? ''),
            sourceSystemId: $sourceSystemId,
            title: (string) ($issue['IssueType'] ?? $issue['Title'] ?? $issue['IssueName'] ?? 'Untitled Issue'),
            severity: self::mapSeverity(self::toNullableString($issue['Severity'] ?? null)),
            state: self::mapState(self::toNullableString($issue['Status'] ?? null)),
            type: self::mapType($issue),
            sourceContainerId: self::toNullableString($issue['ScanId'] ?? null),
            description: self::toNullableString($issue['IssueDescription'] ?? $issue['Description'] ?? null),
            ruleId: self::toNullableString($issue['IssueTypeId'] ?? null),
            fingerprint: self::toNullableString($issue['Fingerprint'] ?? null),
            url: self::toNullableString($issue['Url'] ?? null),
            remediation: self::toNullableString($issue['Remediation'] ?? null),
            filePath: self::extractFilePath($issue),
            startLine: self::extractStartLine($issue),
            endLine: null,
            snippet: null,
            commitSha: null,
            branch: null,
            versionControlUrl: self::extractVersionControlUrl($issue),
            metadata: $metadata,
            firstSeenAt: self::toDateTime($issue['FirstFound'] ?? null),
            lastSeenAt: self::toDateTime($issue['LastUpdated'] ?? null),
        );
    }

    public static function mapSeverity(?string $severity): EventSeverity
    {
        return match (strtolower((string) $severity)) {
            'critical' => EventSeverity::Critical,
            'high' => EventSeverity::High,
            'medium' => EventSeverity::Medium,
            'low' => EventSeverity::Low,
            'informational' => EventSeverity::Informational,
            default => EventSeverity::Medium,
        };
    }

    public static function mapState(?string $state): EventState
    {
        return match (strtolower((string) $state)) {
            'new', 'open', 'reopened' => EventState::Open,
            'inprogress' => EventState::InProgress,
            'fixed' => EventState::Resolved,
            'passed', 'noise' => EventState::Dismissed,
            default => EventState::Open,
        };
    }

    /** @param array<string, mixed> $issue */
    private static function mapType(array $issue): EventType
    {
        $classification = strtolower((string) ($issue['Classification'] ?? ''));
        $scanner = strtolower((string) ($issue['Scanner'] ?? ''));
        $issueType = strtolower((string) ($issue['IssueType'] ?? ''));

        if ($classification === 'secret detection' || str_contains($scanner, 'secret')) {
            return EventType::Secret;
        }

        if (str_contains($issueType, 'sca') || str_contains($classification, 'dependency') || isset($issue['CveId'])) {
            return EventType::Dependency;
        }

        if (str_contains($classification, 'misconfig') || str_contains($issueType, 'misconfig')) {
            return EventType::Misconfiguration;
        }

        return EventType::Vulnerability;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private static function buildMetadata(array $issue): array
    {
        $issueTypeId = self::toNullableString($issue['IssueTypeId'] ?? null);
        $language = self::toNullableString($issue['Language'] ?? null);
        $scanner = self::toNullableString($issue['Scanner'] ?? null);
        $fixGroupId = self::toNullableString($issue['FixGroupId'] ?? null);
        $cve = self::toNullableString($issue['CveId'] ?? null);
        $cwe = self::toNullableString($issue['Cwe'] ?? null);
        $apiVulnName = self::toNullableString($issue['ApiVulnName'] ?? null);
        $classification = self::toNullableString($issue['Classification'] ?? null);
        $sourceFile = self::toNullableString($issue['SourceFile'] ?? null);
        $articleUrl = self::toNullableString($issue['Url'] ?? $issue['Location'] ?? null);

        $metadata = [
            'issueTypeId' => $issueTypeId,
            'language' => $language,
            'scanner' => $scanner,
            'fixGroupId' => $fixGroupId,
            'cve' => $cve,
            'cwe' => $cwe,
            'api' => self::toNullableString($issue['Api'] ?? null),
            'apiVulnName' => $apiVulnName,
            'fingerprint' => self::toNullableString($issue['Fingerprint'] ?? null),
            'classification' => $classification,
            'asoc' => [
                'application' => [
                    'id' => self::toNullableString($issue['ApplicationId'] ?? null),
                    'name' => self::toNullableString($issue['ApplicationName'] ?? null),
                ],
                'scan' => [
                    'id' => self::toNullableString($issue['ScanId'] ?? null),
                    'name' => self::toNullableString($issue['ScanName'] ?? null),
                ],
                'issue_type' => [
                    'id' => $issueTypeId,
                ],
                'scanner' => $scanner,
                'classification' => $classification,
                'language' => $language,
                'api_vulnerability' => [
                    'name' => $apiVulnName,
                ],
                'fix_group' => [
                    'id' => $fixGroupId,
                ],
            ],
        ];

        $safeArticleUrl = is_string($articleUrl) && SourceLinkHelper::isSafeUrl($articleUrl) ? $articleUrl : null;

        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::SECURITY_CVE, $cve);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::SECURITY_CWE, $cwe);
        $metadata = SourceContextFacts::set($metadata, SourceContextFacts::SOURCE_ALERT_WEB_URL, $safeArticleUrl);
        $metadata = SourceContextFacts::set($metadata, 'source.file.path', $sourceFile);
        $metadata = SourceContextFacts::set($metadata, 'asoc.article.url', $safeArticleUrl);

        $links = [];

        $cveId = $metadata['cve'];
        if (is_string($cveId) && $cveId !== '') {
            $cveUrl = SourceLinkHelper::cveLinkUrl($cveId);
            if ($cveUrl !== null) {
                $links[] = ['label' => 'NVD', 'url' => $cveUrl];
            }
        }

        $cweId = $metadata['cwe'];
        if (is_string($cweId) && $cweId !== '') {
            $cweUrl = SourceLinkHelper::cweLinkUrl($cweId);
            if ($cweUrl !== null) {
                $links[] = ['label' => 'CWE', 'url' => $cweUrl];
            }
        }

        if ($sourceFile !== null && SourceLinkHelper::isSafeUrl($sourceFile)) {
            $links[] = ['label' => 'Source file', 'url' => $sourceFile];
        }

        if ($safeArticleUrl !== null) {
            $links[] = ['label' => 'Issue article', 'url' => $safeArticleUrl];
        }

        if ($links !== []) {
            $metadata['links'] = $links;
        }

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $issue */
    private static function extractFilePath(array $issue): ?string
    {
        $sourceFile = self::toNullableString($issue['SourceFile'] ?? null);
        if ($sourceFile !== null) {
            return $sourceFile;
        }

        return self::toNullableString($issue['Location'] ?? null);
    }

    /** @param array<string, mixed> $issue */
    private static function extractStartLine(array $issue): ?int
    {
        $line = $issue['Line'] ?? null;

        if (is_int($line)) {
            return $line;
        }

        if (is_numeric($line)) {
            return (int) $line;
        }

        return null;
    }

    /** @param array<string, mixed> $issue */
    private static function extractVersionControlUrl(array $issue): ?string
    {
        return self::toNullableString($issue['Location'] ?? null);
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function toDateTime(mixed $value): ?\DateTimeInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
