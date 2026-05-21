<?php

use App\Audit\AuditLog;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Triage\BinaryResolver;
use App\Triage\ProcessRunner;
use App\Triage\ProcessRunResult;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(storage_path('app/triage'));
});

it('runs bfg, attaches the report and bundle, and never pushes automatically', function () {
    $commands = [];
    bindFakeBfgDependencies($commands, successful: true);

    $event = SecurityEvent::factory()->create();
    $secretList = base_path('tests/Fixtures/triage/secret-list.txt');

    $this->artisan('triage:bfg', [
        'git_url' => 'https://example.com/bfg-target.git',
        'secret_list_file' => $secretList,
        '--attach-to' => $event->id,
    ])
        ->expectsOutputToContain('BFG run completed.')
        ->expectsOutputToContain('force-push manually if accepted')
        ->assertSuccessful();

    expect(EventAttachment::query()->where('kind', 'bfg-report')->exists())->toBeTrue()
        ->and(EventAttachment::query()->where('kind', 'bfg-bundle')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'triage_run')->exists())->toBeTrue()
        ->and(collect($commands)->contains(fn (array $command): bool => in_array('push', $command, true)))->toBeFalse()
        ->and(triageWorkspaceDirectories())->toBe([]);
});

it('enforces the secret list file size cap', function () {
    $oversized = storage_path('app/triage-secret-list-too-large.txt');
    File::ensureDirectoryExists(dirname($oversized));
    File::put($oversized, str_repeat('A', 1000001));

    try {
        $this->artisan('triage:bfg', [
            'git_url' => 'https://example.com/bfg-target.git',
            'secret_list_file' => $oversized,
        ])
            ->expectsOutputToContain('1 MB limit')
            ->assertExitCode(1);
    } finally {
        File::delete($oversized);
    }
});

it('cleans up the temp directory when bfg fails', function () {
    $commands = [];
    bindFakeBfgDependencies($commands, successful: false);

    $this->artisan('triage:bfg', [
        'git_url' => 'https://example.com/bfg-target.git',
        'secret_list_file' => base_path('tests/Fixtures/triage/secret-list.txt'),
    ])
        ->expectsOutputToContain('BFG failed')
        ->assertExitCode(1);

    expect(triageWorkspaceDirectories())->toBe([]);
});

function bindFakeBfgDependencies(array &$commands, bool $successful): void
{
    app()->bind(BinaryResolver::class, fn () => new class extends BinaryResolver
    {
        public function resolve(string $binary): string
        {
            return match ($binary) {
                'git' => '/usr/bin/git',
                'java' => '/usr/bin/java',
                default => parent::resolve($binary),
            };
        }
    });

    app()->bind(ProcessRunner::class, fn () => new class($commands, $successful) extends ProcessRunner
    {
        /** @param array<int, list<string>> $commands */
        public function __construct(private array &$commands, private readonly bool $successful) {}

        public function run(array $command, ?string $workingDirectory = null, float $timeoutSeconds = 300, int $outputLimitBytes = 100000000): ProcessRunResult
        {
            $this->commands[] = $command;

            if (($command[0] ?? null) === '/usr/bin/git' && ($command[1] ?? null) === 'clone') {
                $fixture = base_path('tests/Fixtures/repos/bfg-target');
                $repoPath = $command[array_key_last($command)];
                File::copyDirectory($fixture, $repoPath);

                return new ProcessRunResult($command, '', '', 0);
            }

            if (($command[0] ?? null) === '/usr/bin/java') {
                if (! $this->successful) {
                    throw new RuntimeException('BFG failed.');
                }

                return new ProcessRunResult($command, 'Found secrets in secrets.txt', '', 0);
            }

            if (($command[0] ?? null) === '/usr/bin/git' && in_array('bundle', $command, true)) {
                $bundlePath = $command[array_search('create', $command, true) + 1];
                File::put($bundlePath, 'bundle-bytes');

                return new ProcessRunResult($command, '', '', 0);
            }

            return new ProcessRunResult($command, '', '', 0);
        }
    });
}
