<?php

namespace App\SourceControl\Collection\Hygiene;

use App\Assets\Parsers\ParsedFinding;
use App\Assets\Parsers\SarifFindingParser;
use Illuminate\Support\Number;

/**
 * Reports build output and compiled binaries committed to a repository — one finding per folder,
 * as a SARIF report RepositoryCollection stores like any scanner's. Neither Trivy nor Opengrep
 * can report this: Trivy's checks read parsed configuration files and Opengrep matches source
 * code, while this is a fact about which files Git tracks. It reads `git ls-files` (the index of
 * the fresh clone), never the working tree, so ignored or generated files never count.
 *
 * A file under a build-output folder (bin/, obj/, publish/, dist/, out/, target/, node_modules/,
 * any case, at any depth; the outermost one names the finding) counts toward that folder. Any
 * other compiled artifact counts toward its own directory. A folder that also holds a finding of
 * the same collection's Trivy secret scan is HIGH instead of MEDIUM: every copy leaks the secret.
 */
final class RepositoryHygieneCheck
{
    public const TOOL = 'repo-hygiene';

    public const RULE_BUILD_OUTPUT = 'repo-hygiene/committed-build-output';

    public const RULE_BINARY = 'repo-hygiene/committed-binary';

    private const BUILD_OUTPUT_DIRECTORIES = ['bin', 'obj', 'publish', 'dist', 'out', 'target', 'node_modules'];

    private const BINARY_EXTENSIONS = ['dll', 'exe', 'jar', 'war', 'ear', 'so', 'pdb', 'class'];

    private const SAMPLE_SIZE = 10;

    private const ROOT = '.';

    public function __construct(private readonly SarifFindingParser $sarifParser) {}

    /**
     * Lists every tracked file with its blob size, NUL-terminated so no path is quoted.
     *
     * @return list<string>
     */
    public function command(string $workDir): array
    {
        return ['git', '-C', $workDir, 'ls-files', '-z', '--format=%(objecttype)%x09%(objectsize)%x09%(path)'];
    }

    /**
     * @param  string  $lsFilesOutput  the output of command()
     * @param  string|null  $secretsReport  the same collection's Trivy secret SARIF, when that scan succeeded
     * @return list<CommittedBuildOutput>
     */
    public function findings(string $lsFilesOutput, ?string $secretsReport): array
    {
        $secretPaths = $secretsReport === null
            ? []
            : array_map(fn (ParsedFinding $finding): string => $finding->filePath, $this->sarifParser->parse($secretsReport));

        $groups = [];

        foreach ($this->trackedFiles($lsFilesOutput) as $path => $size) {
            $match = $this->classify($path);

            if ($match === null) {
                continue;
            }

            $key = $match['folder'] . "\0" . $match['rule'];
            $groups[$key] ??= ['rule' => $match['rule'], 'folder' => $match['folder'], 'files' => [], 'size' => 0];
            $groups[$key]['files'][] = $path;
            $groups[$key]['size'] += $size;
        }

        ksort($groups, SORT_STRING);

        return array_values(array_map(function (array $group) use ($secretPaths): CommittedBuildOutput {
            sort($group['files'], SORT_STRING);

            return new CommittedBuildOutput(
                ruleId: $group['rule'],
                folder: $group['folder'],
                fileCount: count($group['files']),
                sizeBytes: $group['size'],
                secretCount: count(array_filter($secretPaths, fn (string $secretPath): bool => $this->contains($group['rule'], $group['folder'], $secretPath))),
                sampleFiles: array_slice($group['files'], 0, self::SAMPLE_SIZE),
            );
        }, $groups));
    }

    /** @param  list<CommittedBuildOutput>  $findings */
    public function report(array $findings): string
    {
        return json_encode([
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'AppSec Scout repository hygiene',
                    'informationUri' => 'https://github.com/ossf/scorecard/blob/main/docs/checks.md#binary-artifacts',
                    'rules' => [
                        $this->rule(self::RULE_BUILD_OUTPUT, 'Build output committed to Git', 'Build output (bin, obj, publish, dist, out, target or node_modules) is committed to the repository.'),
                        $this->rule(self::RULE_BINARY, 'Compiled binary committed to Git', 'Compiled binaries (.dll, .exe, .jar, .war, .ear, .so, .pdb or .class files) are committed to the repository.'),
                    ],
                ]],
                'results' => array_map(fn (CommittedBuildOutput $finding): array => $this->result($finding), $findings),
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Blobs only: a submodule (a commit entry) is another repository, not a committed file.
     *
     * @return array<string, int> path => size in bytes
     */
    private function trackedFiles(string $lsFilesOutput): array
    {
        $files = [];

        foreach (explode("\0", $lsFilesOutput) as $record) {
            $fields = explode("\t", $record, 3);

            if (count($fields) === 3 && $fields[0] === 'blob' && $fields[2] !== '') {
                $files[$fields[2]] = (int) $fields[1];
            }
        }

        return $files;
    }

    /** @return array{rule: string, folder: string}|null */
    private function classify(string $path): ?array
    {
        $segments = explode('/', $path);

        foreach (array_slice($segments, 0, -1) as $index => $segment) {
            if (in_array(strtolower($segment), self::BUILD_OUTPUT_DIRECTORIES, true)) {
                return ['rule' => self::RULE_BUILD_OUTPUT, 'folder' => implode('/', array_slice($segments, 0, $index + 1))];
            }
        }

        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY_EXTENSIONS, true)) {
            return ['rule' => self::RULE_BINARY, 'folder' => $this->directoryOf($path)];
        }

        return null;
    }

    /**
     * A build-output folder holds everything below it; a binaries finding stands for one
     * directory's own files, so only a secret in that same directory counts.
     */
    private function contains(string $ruleId, string $folder, string $path): bool
    {
        return $ruleId === self::RULE_BUILD_OUTPUT
            ? str_starts_with($path, $folder . '/')
            : $this->directoryOf($path) === $folder;
    }

    private function directoryOf(string $path): string
    {
        $separator = strrpos($path, '/');

        return $separator === false ? self::ROOT : substr($path, 0, $separator);
    }

    /** @return array<string, mixed> */
    private function rule(string $id, string $title, string $description): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'shortDescription' => ['text' => $title],
            'fullDescription' => ['text' => $description],
            'helpUri' => 'https://github.com/ossf/scorecard/blob/main/docs/checks.md#binary-artifacts',
            'help' => ['text' => $description, 'markdown' => $this->help($description)],
            'defaultConfiguration' => ['level' => 'warning'],
            'properties' => ['tags' => ['repo-hygiene', 'supply-chain', 'CICD-SEC-9']],
        ];
    }

    private function help(string $description): string
    {
        return <<<MARKDOWN
            {$description}

            Build output belongs to the build, not to source control. A committed copy:

            - cannot be traced back to the source it was built from, so a tampered or outdated binary goes unnoticed;
            - duplicates configuration: an `appsettings.json` copied into `bin/`, `obj/` and `publish/` leaks the same secret once per copy, and every copy stays in the Git history after the source file is fixed;
            - is reported again by every dependency scanner, once per copy, and is never updated by a dependency upgrade.

            **Remediation**

            1. Stop tracking the folder or files: `git rm -r --cached <folder>`, then commit.
            2. Add them to `.gitignore` so they are not committed again.
            3. Build them in the CI/CD pipeline and publish them to an artifact feed or registry, where their integrity can be verified.
            4. If the folder holds a secret, rotate it first: deleting the file leaves the secret in the Git history (rewrite it with BFG or `git filter-repo` only once the secret is rotated).
            5. A third-party binary with no package source should come from a package feed, or be documented with its origin and checksum.

            **References**

            - OWASP Top 10 CI/CD Security Risks, [CICD-SEC-9: Improper Artifact Integrity Validation](https://owasp.org/www-project-top-10-ci-cd-security-risks/CICD-SEC-09-Improper-Artifact-Integrity-Validation)
            - NIST Secure Software Development Framework (SP 800-218), [PS.2: Provide a mechanism for verifying software release integrity](https://csrc.nist.gov/pubs/sp/800/218/final)
            - OpenSSF Scorecard, [Binary-Artifacts check](https://github.com/ossf/scorecard/blob/main/docs/checks.md#binary-artifacts)
            MARKDOWN;
    }

    /**
     * The message carries "Severity:" as its last line: SarifFindingParser reads Trivy's severity
     * the same way, and the last line wins. File names are put on one line each, so a file name
     * holding a line break cannot add a line of its own.
     *
     * @return array<string, mixed>
     */
    private function result(CommittedBuildOutput $finding): array
    {
        $size = Number::fileSize($finding->sizeBytes, maxPrecision: 1);
        $folder = $this->singleLine($finding->folder);
        $lines = [
            sprintf('%d committed %s (%s) in %s', $finding->fileCount, $finding->fileCount === 1 ? 'file' : 'files', $size, $finding->folder === self::ROOT ? 'the repository root' : $folder),
            'Folder: ' . $folder,
            'Files: ' . $finding->fileCount,
            'Size: ' . $size,
            'Examples: ' . implode(', ', array_map($this->singleLine(...), $finding->sampleFiles)),
        ];

        if ($finding->secretCount > 0) {
            $lines[] = 'Secrets: ' . $finding->secretCount . ' Trivy secret ' . ($finding->secretCount === 1 ? 'finding' : 'findings') . ' in this folder';
        }

        $lines[] = 'Severity: ' . $finding->severity();

        return [
            'ruleId' => $finding->ruleId,
            'level' => $finding->secretCount > 0 ? 'error' : 'warning',
            'message' => ['text' => implode("\n", $lines)],
            'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $this->uri($finding->folder)]]]],
            'properties' => [
                'folder' => $finding->folder,
                'fileCount' => $finding->fileCount,
                'sizeBytes' => $finding->sizeBytes,
                'secretCount' => $finding->secretCount,
                'sampleFiles' => $finding->sampleFiles,
            ],
        ];
    }

    private function singleLine(string $text): string
    {
        return str_replace(["\r", "\n"], ' ', $text);
    }

    /** SarifFindingParser percent-decodes a relative uri, so each segment is encoded here. */
    private function uri(string $folder): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $folder)));
    }
}
