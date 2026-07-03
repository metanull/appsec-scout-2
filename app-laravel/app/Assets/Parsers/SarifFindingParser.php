<?php

namespace App\Assets\Parsers;

use JsonException;

/**
 * Parses a Trivy SARIF report (`trivy fs --format sarif`, vulnerability or
 * secret scanners) into ParsedFinding entries. SARIF has no first-class
 * concept of a package, so Trivy encodes it as "Key: Value" lines in the
 * result message text — parsed here by splitting lines, not by regex.
 */
final class SarifFindingParser
{
    /**
     * @return list<ParsedFinding>
     */
    public function parse(string $payload): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $run = $data['runs'][0] ?? null;

        if (! is_array($run)) {
            return [];
        }

        $rules = $this->indexRules($run);
        $results = $run['results'] ?? null;

        if (! is_array($results)) {
            return [];
        }

        $parsed = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $ruleId = $result['ruleId'] ?? null;

            if (! is_string($ruleId) || $ruleId === '') {
                continue;
            }

            $rule = $rules[$ruleId] ?? [];
            $messageFields = $this->parseMessageFields((string) ($result['message']['text'] ?? ''));
            $locations = $result['locations'] ?? [];

            if (! is_array($locations)) {
                continue;
            }

            foreach ($locations as $location) {
                $finding = $this->buildFinding($ruleId, $rule, $messageFields, $result, $location);

                if ($finding !== null) {
                    $parsed[] = $finding;
                }
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, array<string, mixed>>
     */
    private function indexRules(array $run): array
    {
        $rules = $run['tool']['driver']['rules'] ?? null;

        if (! is_array($rules)) {
            return [];
        }

        $indexed = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && is_string($rule['id'] ?? null)) {
                $indexed[$rule['id']] = $rule;
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, string>
     */
    private function parseMessageFields(string $text): array
    {
        $fields = [];

        foreach (explode("\n", $text) as $line) {
            if (! str_contains($line, ': ')) {
                continue;
            }

            [$key, $value] = explode(': ', $line, 2);
            $fields[trim($key)] = trim($value);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $messageFields
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $location
     */
    private function buildFinding(string $ruleId, array $rule, array $messageFields, array $result, array $location): ?ParsedFinding
    {
        $artifactLocation = $location['physicalLocation']['artifactLocation'] ?? null;
        $filePath = is_array($artifactLocation) ? ($artifactLocation['uri'] ?? null) : null;

        if (! is_string($filePath) || $filePath === '') {
            return null;
        }

        $region = $location['physicalLocation']['region'] ?? null;
        $startLine = is_array($region) && is_int($region['startLine'] ?? null) ? $region['startLine'] : null;
        $endLine = is_array($region) && is_int($region['endLine'] ?? null) ? $region['endLine'] : $startLine;

        $shortDescription = $rule['shortDescription']['text'] ?? null;
        $title = is_string($shortDescription) && $shortDescription !== ''
            ? $shortDescription
            : $ruleId;

        $fullDescription = $rule['fullDescription']['text'] ?? null;

        return new ParsedFinding(
            ruleId: $ruleId,
            title: $title,
            description: is_string($fullDescription) ? $fullDescription : null,
            severity: $messageFields['Severity'] ?? null,
            filePath: $filePath,
            startLine: $startLine,
            endLine: $endLine,
            packageName: $messageFields['Package'] ?? null,
            packageVersion: $messageFields['Installed Version'] ?? null,
            metadata: [
                'helpUri' => $rule['helpUri'] ?? null,
                'ruleProperties' => $rule['properties'] ?? [],
                'result' => $result,
            ],
        );
    }
}
