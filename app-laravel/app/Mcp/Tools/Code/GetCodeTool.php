<?php

namespace App\Mcp\Tools\Code;

use App\Mcp\Support\AzDoReposAccess;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\AzDo\AzDoClientFactory;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-code')]
#[Title('Read code from Azure DevOps')]
#[Description('Returns numbered lines of one file of an Azure DevOps repository, read with your own Azure DevOps Repos credential (Profile → Integrations): the code around an alert or local finding (its file and line), or any file of a container given its path. Use it to judge whether a finding is reachable, exploitable or a false positive. context_lines sets how many lines to show on each side of the line (default 20). Reads the container\'s default branch unless a branch is given (an alert\'s own branch when it has one). Requires alerts.view.')]
#[IsReadOnly]
final class GetCodeTool extends AppSecTool
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly AzDoReposAccess $access,
        private readonly AzDoClientFactory $clients,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'local_finding_id' => $schema->integer()->min(1)->description('Show the code around this local finding.'),
            'alert_id' => $schema->integer()->min(1)->description('Show the code around this alert.'),
            'container_id' => $schema->integer()->min(1)->description('Read a file of this container\'s repository; requires path.'),
            'path' => $schema->string()->max(1000)->description('With container_id: the file path in the repository.'),
            'line' => $schema->integer()->min(1)->description('With container_id: the line to centre on. Without it, the file is shown from the top.'),
            'context_lines' => $schema->integer()->min(0)->max(100)->default(20)->description('Lines shown on each side of the line.'),
            'branch' => $schema->string()->max(250)->description('Branch to read instead of the default one.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'local_finding_id' => ['nullable', 'integer', 'exists:local_findings,id'],
            'alert_id' => ['nullable', 'integer', 'exists:security_events,id'],
            'container_id' => ['nullable', 'integer', 'exists:security_containers,id', 'required_with:path'],
            'path' => ['nullable', 'string', 'max:1000', 'required_with:container_id'],
            'line' => ['nullable', 'integer', 'min:1'],
            'context_lines' => ['nullable', 'integer', 'min:0', 'max:100'],
            'branch' => ['nullable', 'string', 'max:250'],
        ], [
            'local_finding_id.exists' => 'No local finding with id :input.',
            'alert_id.exists' => 'No alert with id :input.',
            'container_id.exists' => 'No security container with id :input.',
        ]);

        ['container' => $container, 'path' => $path, 'line' => $line, 'branch' => $targetBranch] = $this->target($validated);

        $project = AzDoReposAccess::projectOf($container);
        $branch = $validated['branch'] ?? $targetBranch ?? $this->defaultBranch($container);
        $credentials = $this->access->credentials($actor);
        $repositoryPath = '/' . ltrim($path, '/');

        $content = $this->clients->make($credentials['organization'], $credentials['pat'])
            ->getFileContent((string) $project->source_system_id, (string) $container->source_container_id, $repositoryPath, $branch);

        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException("{$repositoryPath} is larger than 1 MB; read it in the repository instead.");
        }

        return Response::structured([
            'container' => ['id' => $container->id, 'name' => $container->name],
            'path' => $repositoryPath,
            'branch' => $branch,
            ...self::window(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)), $line, (int) ($validated['context_lines'] ?? 20)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{container: SecurityContainer, path: string, line: int|null, branch: string|null}
     */
    private function target(array $validated): array
    {
        $given = array_filter([
            isset($validated['local_finding_id']),
            isset($validated['alert_id']),
            isset($validated['container_id']),
        ]);

        if (count($given) !== 1) {
            throw ValidationException::withMessages(['target' => 'Provide exactly one of local_finding_id, alert_id, or container_id with path.']);
        }

        if (isset($validated['local_finding_id'])) {
            $finding = LocalFinding::query()->findOrFail((int) $validated['local_finding_id']);
            $owner = $finding->owner;

            if (! $owner instanceof SecurityContainer) {
                throw new InvalidArgumentException("Local finding #{$finding->id} belongs to a software system or asset, not to a repository, so it has no code to show.");
            }

            return ['container' => $owner, 'path' => self::requirePath($finding->file_path, "Local finding #{$finding->id}"), 'line' => $finding->start_line, 'branch' => null];
        }

        if (isset($validated['alert_id'])) {
            $alert = SecurityEvent::query()->with('container')->findOrFail((int) $validated['alert_id']);

            $container = $alert->container
                ?? throw new InvalidArgumentException("Alert #{$alert->id} is not attached to a repository, so it has no code to show.");

            return ['container' => $container, 'path' => self::requirePath($alert->file_path, "Alert #{$alert->id}"), 'line' => $alert->start_line, 'branch' => self::branchName($alert->branch)];
        }

        return [
            'container' => SecurityContainer::query()->findOrFail((int) $validated['container_id']),
            'path' => (string) $validated['path'],
            'line' => isset($validated['line']) ? (int) $validated['line'] : null,
            'branch' => null,
        ];
    }

    private static function requirePath(?string $path, string $what): string
    {
        if ($path === null || trim($path) === '') {
            throw new InvalidArgumentException("{$what} has no file path, so it has no code to show.");
        }

        return $path;
    }

    /** "refs/heads/main" and "main" both name the main branch. */
    private static function branchName(?string $branch): ?string
    {
        if ($branch === null || trim($branch) === '') {
            return null;
        }

        return str_starts_with($branch, 'refs/heads/') ? substr($branch, strlen('refs/heads/')) : $branch;
    }

    private function defaultBranch(SecurityContainer $container): ?string
    {
        return self::branchName(SourceContextFacts::getString(
            Format::arrayAttribute($container->getAttribute('metadata')),
            SourceContextFacts::CODE_DEFAULT_BRANCH,
        ));
    }

    /**
     * @param  list<string>  $lines
     * @return array{total_lines: int, focus_line: int|null, start_line: int, end_line: int, lines: list<array{number: int, text: string}>}
     */
    private static function window(array $lines, ?int $focus, int $context): array
    {
        $total = count($lines);

        if ($focus !== null && $focus > $total) {
            throw new InvalidArgumentException("Line {$focus} is beyond the end of the file ({$total} lines).");
        }

        $start = $focus === null ? 1 : max(1, $focus - $context);
        $end = $focus === null ? min($total, 2 * $context + 1) : min($total, $focus + $context);
        $window = [];

        for ($number = $start; $number <= $end; $number++) {
            $window[] = ['number' => $number, 'text' => $lines[$number - 1]];
        }

        return ['total_lines' => $total, 'focus_line' => $focus, 'start_line' => $start, 'end_line' => $end, 'lines' => $window];
    }
}
