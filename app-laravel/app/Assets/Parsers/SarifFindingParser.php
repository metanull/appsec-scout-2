<?php

namespace App\Assets\Parsers;

use Illuminate\Support\Str;
use JsonException;

/**
 * Parses a SARIF report into ParsedFinding entries — Trivy's (`trivy fs --format
 * sarif`, vulnerability or secret scanners) as well as Roslynator's and SpotBugs'
 * (static analysis). SARIF has no first-class concept of a package, so Trivy
 * encodes it as "Key: Value" lines in the result message text — parsed here by
 * splitting lines, not by regex. Trivy severity comes from that same "Severity:"
 * line; Roslynator/SpotBugs instead use the standard SARIF `level` field
 * (error/warning/note), used as a fallback when no "Severity:" line is present.
 */
final class SarifFindingParser
{
    public function __construct(
        private readonly SarifArtifactUriNormalizer $uriNormalizer,
    ) {}

    /**
     * @return list<ParsedFinding>
     */
    public function parse(string $payload, ?string $sourceRoot = null): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $runs = $data['runs'] ?? null;

        if (! is_array($runs)) {
            return [];
        }

        $parsed = [];

        foreach ($runs as $run) {
            if (is_array($run)) {
                array_push($parsed, ...$this->parseRun($run, $sourceRoot));
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<ParsedFinding>
     */
    private function parseRun(array $run, ?string $sourceRoot): array
    {
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
                $finding = $this->buildFinding($ruleId, $rule, $messageFields, $result, $location, $sourceRoot);

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
    private function buildFinding(string $ruleId, array $rule, array $messageFields, array $result, array $location, ?string $sourceRoot): ?ParsedFinding
    {
        $artifactLocation = $location['physicalLocation']['artifactLocation'] ?? null;
        $filePath = is_array($artifactLocation) ? ($artifactLocation['uri'] ?? null) : null;

        if (! is_string($filePath) || $filePath === '') {
            return null;
        }

        $filePath = $this->uriNormalizer->normalize($filePath, $sourceRoot);

        $region = $location['physicalLocation']['region'] ?? null;
        $startLine = is_array($region) && is_int($region['startLine'] ?? null) ? $region['startLine'] : null;
        $endLine = is_array($region) && is_int($region['endLine'] ?? null) ? $region['endLine'] : $startLine;

        $messageText = (string) ($result['message']['text'] ?? '');
        $fullDescription = $rule['fullDescription']['text'] ?? null;
        $level = $this->levelFor($result, $rule);

        return new ParsedFinding(
            ruleId: $ruleId,
            title: $this->titleFor($ruleId, $rule, $messageText),
            description: is_string($fullDescription) ? $fullDescription : null,
            severity: $messageFields['Severity'] ?? $this->severityFromLevel($level),
            filePath: $filePath,
            startLine: $startLine,
            endLine: $endLine,
            packageName: $messageFields['Package'] ?? null,
            packageVersion: $messageFields['Installed Version'] ?? null,
            metadata: [
                'helpUri' => $rule['helpUri'] ?? null,
                'ruleProperties' => $rule['properties'] ?? [],
                'message' => $messageText !== '' ? $messageText : null,
                'help' => $this->helpFor($rule),
                'tags' => $this->tagsFor($rule),
                'level' => $level,
                'result' => $result,
            ],
        );
    }

    /**
     * Derives a meaningful title in deterministic precedence order: the rule's
     * shortDescription (unless it is empty, is the rule id itself, or merely
     * restates it — Opengrep/Semgrep emit "<Tool> Finding: <rule.id>"), else the
     * rule's name (unless it equals the rule id), else the first non-empty line
     * of the result message, else the rule id itself.
     *
     * @param  array<string, mixed>  $rule
     */
    private function titleFor(string $ruleId, array $rule, string $messageText): string
    {
        $short = trim((string) ($rule['shortDescription']['text'] ?? ''));

        if ($short !== '' && ! $this->matchesRuleId($short, $ruleId)) {
            return $short;
        }

        $name = trim((string) ($rule['name'] ?? ''));

        if ($name !== '' && strcasecmp($name, $ruleId) !== 0) {
            return $name;
        }

        foreach (explode("\n", $messageText) as $line) {
            $line = trim($line);

            if ($line !== '') {
                return Str::limit($line, 250);
            }
        }

        return $ruleId;
    }

    /**
     * True when $text is exactly the rule id or ends with it (case-insensitive) —
     * the latter covers "Opengrep Finding: <id>"-style shortDescription text.
     */
    private function matchesRuleId(string $text, string $ruleId): bool
    {
        $textLower = strtolower($text);
        $ruleIdLower = strtolower($ruleId);

        return $textLower === $ruleIdLower || str_ends_with($textLower, $ruleIdLower);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function helpFor(array $rule): ?string
    {
        $markdown = $rule['help']['markdown'] ?? null;

        if (is_string($markdown) && $markdown !== '') {
            return $markdown;
        }

        $text = $rule['help']['text'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private function tagsFor(array $rule): array
    {
        $tags = $rule['properties']['tags'] ?? null;

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, fn (mixed $tag): bool => is_string($tag) && $tag !== ''));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $rule
     */
    private function levelFor(array $result, array $rule): ?string
    {
        $level = $result['level'] ?? null;

        if (is_string($level) && $level !== '') {
            return $level;
        }

        $default = $rule['defaultConfiguration']['level'] ?? null;

        return is_string($default) && $default !== '' ? $default : null;
    }

    private function severityFromLevel(mixed $level): ?string
    {
        if (! is_string($level)) {
            return null;
        }

        return match ($level) {
            'error' => 'HIGH',
            'warning' => 'MEDIUM',
            'note' => 'LOW',
            default => null,
        };
    }
}
