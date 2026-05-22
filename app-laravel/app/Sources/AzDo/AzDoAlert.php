<?php

namespace App\Sources\AzDo;

final class AzDoAlert
{
    /**
     * @phpstan-param list<array<string, mixed>> $physicalLocations
     * @phpstan-param list<array<string, mixed>> $logicalLocations
     * @phpstan-param list<array<string, mixed>> $tools
     * @phpstan-param list<array<string, mixed>> $validationFingerprints
     * @phpstan-param array<string, mixed>|null $additionalData
     */
    public function __construct(
        public readonly int $alertId,
        public readonly string $alertType,
        public readonly string $severity,
        public readonly string $state,
        public readonly string $title,
        public readonly ?string $alertUri = null,
        public readonly ?string $firstSeenDate = null,
        public readonly ?string $lastSeenDate = null,
        public readonly array $physicalLocations = [],
        public readonly array $logicalLocations = [],
        public readonly array $tools = [],
        public readonly ?string $truncatedSecret = null,
        public readonly array $validationFingerprints = [],
        public readonly ?array $additionalData = null,
    ) {}

    public function descriptionReady(): bool
    {
        return $this->alertUri !== null
            && ($this->physicalLocations !== [] || $this->logicalLocations !== [] || $this->tools !== [] || $this->additionalData !== null);
    }

    /** @phpstan-param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            alertId: (int) $data['alertId'],
            alertType: strtolower((string) $data['alertType']),
            severity: strtolower((string) ($data['severity'] ?? 'medium')),
            state: strtolower((string) ($data['state'] ?? 'active')),
            title: (string) ($data['title'] ?? 'Untitled'),
            alertUri: isset($data['alertUri']) ? (string) $data['alertUri'] : null,
            firstSeenDate: isset($data['firstSeenDate']) ? (string) $data['firstSeenDate'] : null,
            lastSeenDate: isset($data['lastSeenDate']) ? (string) $data['lastSeenDate'] : null,
            physicalLocations: self::toListOfMaps($data['physicalLocations'] ?? []),
            logicalLocations: self::toListOfMaps($data['logicalLocations'] ?? []),
            tools: self::toListOfMaps($data['tools'] ?? []),
            truncatedSecret: isset($data['truncatedSecret']) ? (string) $data['truncatedSecret'] : null,
            validationFingerprints: self::toListOfMaps($data['validationFingerprints'] ?? []),
            additionalData: isset($data['additionalData']) ? (array) $data['additionalData'] : null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toListOfMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }
}
