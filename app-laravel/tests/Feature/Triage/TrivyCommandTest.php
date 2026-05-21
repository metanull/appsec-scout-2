<?php

use App\Audit\AuditLog;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Triage\BinaryResolver;
use App\Triage\ProcessRunner;
use App\Triage\ProcessRunResult;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    File::deleteDirectory(storage_path('app/triage'));
});

it('attaches sarif output for a successful trivy scan', function () {
    bindFakeTrivyDependencies(successful: true);

    $event = SecurityEvent::factory()->create();

    $this->artisan('triage:trivy', [
        'git_url' => 'https://example.com/trivy-target.git',
        '--attach-to' => $event->id,
    ])
        ->expectsOutputToContain('Trivy scan completed.')
        ->expectsOutputToContain('Attached SARIF output to alert')
        ->assertSuccessful();

    $attachment = EventAttachment::query()->first();

    expect($attachment)->not()->toBeNull()
        ->and($attachment?->kind)->toBe('trivy-sarif')
        ->and($attachment?->mime)->toBe('application/sarif+json')
        ->and($attachment?->payload)->toContain('TRIVY-001')
        ->and(AuditLog::query()->where('action', 'triage_run')->exists())->toBeTrue()
        ->and(triageWorkspaceDirectories())->toBe([]);
});

it('enforces timeout failures from the process runner', function () {
    bindTimedOutTrivyRunner();

    $this->artisan('triage:trivy', [
        'git_url' => 'https://example.com/trivy-target.git',
    ])
        ->expectsOutputToContain('exceeded the timeout')
        ->assertExitCode(1);
});

it('rejects shell metacharacters in the repository url', function () {
    $this->artisan('triage:trivy', [
        'git_url' => 'https://example.com/repo.git; rm -rf /',
    ])
        ->expectsOutputToContain('forbidden characters')
        ->assertExitCode(1);
});

it('cleans up the temp directory when the scan fails', function () {
    bindFakeTrivyDependencies(successful: false);

    $this->artisan('triage:trivy', [
        'git_url' => 'https://example.com/trivy-target.git',
    ])
        ->expectsOutputToContain('Trivy failed')
        ->assertExitCode(1);

    expect(triageWorkspaceDirectories())->toBe([]);
});

function bindFakeTrivyDependencies(bool $successful): void
{
    app()->bind(BinaryResolver::class, fn () => new class extends BinaryResolver
    {
        public function resolve(string $binary): string
        {
            return match ($binary) {
                'git' => '/usr/bin/git',
                'trivy' => '/usr/bin/trivy',
                default => parent::resolve($binary),
            };
        }
    });

    app()->bind(ProcessRunner::class, fn () => new class($successful) extends ProcessRunner
    {
        public function __construct(private readonly bool $successful) {}

        public function run(array $command, ?string $workingDirectory = null, float $timeoutSeconds = 300, int $outputLimitBytes = 100000000): ProcessRunResult
        {
            if (($command[0] ?? null) === '/usr/bin/git') {
                $fixture = base_path('tests/Fixtures/repos/trivy-target');
                $clonePath = $command[array_key_last($command)];
                File::copyDirectory($fixture, $clonePath);

                return new ProcessRunResult($command, '', '', 0);
            }

            if (! $this->successful) {
                throw new RuntimeException('Trivy failed.');
            }

            $sarifPath = $command[array_search('--output', $command, true) + 1];
            File::put($sarifPath, json_encode([
                'runs' => [[
                    'results' => [[
                        'ruleId' => 'TRIVY-001',
                        'message' => ['text' => 'Example result'],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR));

            return new ProcessRunResult($command, '', '', 0);
        }
    });
}

function bindTimedOutTrivyRunner(): void
{
    app()->bind(ProcessRunner::class, fn () => new class extends ProcessRunner
    {
        public function run(array $command, ?string $workingDirectory = null, float $timeoutSeconds = 300, int $outputLimitBytes = 100000000): ProcessRunResult
        {
            throw new ProcessTimedOutException(new Process($command), ProcessTimedOutException::TYPE_GENERAL);
        }
    });
}
