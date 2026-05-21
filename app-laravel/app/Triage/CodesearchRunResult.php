<?php

namespace App\Triage;

final class CodesearchRunResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly array $payload) {}

    public function json(): string
    {
        return json_encode($this->payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function totalCount(): int
    {
        $count = $this->payload['count'] ?? null;

        return is_int($count) ? $count : count($this->results());
    }

    /** @return list<array<string, mixed>> */
    public function results(): array
    {
        $results = $this->payload['results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /** @return list<array{project:string, repository:string, path:string, matches:string}> */
    public function tableRows(): array
    {
        return array_map(function (array $result): array {
            $hits = $result['hits'] ?? [];

            return [
                'project' => $this->stringValue($result['project']['name'] ?? $result['project']['projectName'] ?? null),
                'repository' => $this->stringValue($result['repository']['name'] ?? $result['repository']['repositoryName'] ?? null),
                'path' => $this->stringValue($result['path'] ?? null),
                'matches' => (string) (is_countable($hits) ? count($hits) : 0),
            ];
        }, $this->results());
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'n/a';
    }
}
