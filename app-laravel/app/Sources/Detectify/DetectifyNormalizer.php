<?php

namespace App\Sources\Detectify;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;

final class DetectifyNormalizer
{
    public const SOURCE_ID = 'detectify';

    /** @param array<string, mixed> $domain */
    public static function toSystem(array $domain): SystemDto
    {
        $token = (string) ($domain['token'] ?? $domain['asset_token'] ?? '');

        return new SystemDto(
            sourceSystemId: $token,
            name: (string) ($domain['name'] ?? $domain['host'] ?? 'Unknown Domain'),
            description: null,
            url: self::toNullableString($domain['url'] ?? null),
            metadata: $domain,
        );
    }

    /** @param array<string, mixed> $finding */
    public static function toEvent(array $finding): EventDto
    {
        $domainToken = self::toNullableString($finding['asset_token'] ?? $finding['domain_token'] ?? null);
        $metadata = [
            'domainToken' => $domainToken,
            'cwe' => self::toNullableString($finding['cwe'] ?? null),
        ];

        return new EventDto(
            sourceEventId: (string) ($finding['uuid'] ?? ''),
            sourceSystemId: (string) ($domainToken ?? ''),
            title: (string) ($finding['title'] ?? 'Untitled Finding'),
            severity: self::mapSeverity(self::toNullableString($finding['severity'] ?? null)),
            state: self::mapState(self::toNullableString($finding['status'] ?? null)),
            type: self::mapType($finding),
            sourceContainerId: null,
            description: self::toNullableString($finding['description'] ?? $finding['definition']['description'] ?? null),
            ruleId: self::toNullableString($finding['cwe'] ?? null),
            fingerprint: self::toNullableString($finding['fingerprint'] ?? $finding['uuid'] ?? null),
            url: self::toNullableString($finding['links']['details_page'] ?? null),
            remediation: self::toNullableString($finding['definition']['remediation'] ?? null),
            filePath: self::toNullableString($finding['location'] ?? null),
            startLine: null,
            endLine: null,
            snippet: null,
            commitSha: null,
            branch: null,
            versionControlUrl: null,
            metadata: array_filter($metadata, static fn (mixed $value): bool => $value !== null),
            firstSeenAt: self::toDateTime($finding['created_at'] ?? null),
            lastSeenAt: self::toDateTime($finding['updated_at'] ?? null),
        );
    }

    public static function mapSeverity(?string $severity): EventSeverity
    {
        return match (strtolower((string) $severity)) {
            'critical' => EventSeverity::Critical,
            'high' => EventSeverity::High,
            'medium' => EventSeverity::Medium,
            'low' => EventSeverity::Low,
            'information', 'informational' => EventSeverity::Informational,
            default => EventSeverity::Medium,
        };
    }

    public static function mapState(?string $status): EventState
    {
        return match (strtolower((string) $status)) {
            'patched' => EventState::Resolved,
            'accepted_risk', 'false_positive' => EventState::Dismissed,
            default => EventState::Open,
        };
    }

    public static function mapStateToSource(EventState $state): string
    {
        return match ($state) {
            EventState::Resolved => 'patched',
            EventState::Dismissed => 'false_positive',
            default => 'active',
        };
    }

    /** @param array<string, mixed> $finding */
    private static function mapType(array $finding): EventType
    {
        $category = strtolower((string) ($finding['category'] ?? ''));

        if (str_contains($category, 'misconfig')) {
            return EventType::Misconfiguration;
        }

        return EventType::Vulnerability;
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
