<?php

namespace App\Models\Enums;

use App\Models\SecurityEvent;
use App\Sources\AzDo\AzDoNormalizer;

/**
 * The reasons an operator can give when dismissing an Azure DevOps Advanced Security alert —
 * the user-selectable values of the `DismissalType` enum that the alert update API
 * (`PATCH .../alert/repositories/{repo}/alerts/{id}`, api-version 7.2-preview.1) accepts as
 * `dismissedReason`. The service-set values (`unknown`, `fixed`, `toolUpgrade`) are
 * deliberately not offered.
 */
enum AzDoDismissalReason: string
{
    case AcceptedRisk = 'acceptedRisk';
    case FalsePositive = 'falsePositive';
    case NotDistributed = 'notDistributed';
    case AgreedToGuidance = 'agreedToGuidance';

    public function label(): string
    {
        return match ($this) {
            self::AcceptedRisk => 'Accepted risk',
            self::FalsePositive => 'False positive',
            self::NotDistributed => 'Not distributed (dependency never ships)',
            self::AgreedToGuidance => 'Agreed to license guidance',
        };
    }

    /**
     * `notDistributed` describes a dependency that never reaches end users, so it applies to
     * dependency alerts only; `agreedToGuidance` accepts a license's terms, so it applies to
     * license alerts only.
     */
    public function appliesTo(EventType $type): bool
    {
        return match ($this) {
            self::NotDistributed => $type === EventType::Dependency,
            self::AgreedToGuidance => $type === EventType::License,
            default => true,
        };
    }

    /** Whether dismissing this alert needs a reason: only AzDO alerts carry one upstream. */
    public static function isRequiredFor(SecurityEvent $event): bool
    {
        return $event->source_id === AzDoNormalizer::SOURCE_ID;
    }

    /**
     * The reasons valid for every one of the given alert types.
     *
     * @param  iterable<EventType>  $types
     * @return list<self>
     */
    public static function applicableToAll(iterable $types): array
    {
        $reasons = self::cases();

        foreach ($types as $type) {
            $reasons = array_values(array_filter($reasons, fn (self $reason): bool => $reason->appliesTo($type)));
        }

        return $reasons;
    }
}
