<?php

use App\Audit\AuditLog;
use App\Credentials\Vault;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Triage\CodesearchClient;
use App\Triage\CodesearchClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Exception\RuntimeException;

it('fails when required arguments are missing', function () {
    expect(fn () => $this->artisan('triage:codesearch'))
        ->toThrow(RuntimeException::class, 'Not enough arguments');
});

it('fails fast when no --pat is given and no system credential is configured', function () {
    $this->artisan('triage:codesearch', ['search' => 'openssl'])
        ->expectsOutputToContain('AzDO PAT not provided via --pat, and no system credential "azdo-repos.pat" is configured.')
        ->assertExitCode(1);
});

it('fails fast when no --organization is given and no system credential is configured', function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'system-pat');

    $this->artisan('triage:codesearch', ['search' => 'openssl'])
        ->expectsOutputToContain('AzDO organization not provided via --organization, and no system credential "azdo-repos.organization" is configured.')
        ->assertExitCode(1);
});

it('falls back to the system credential when --pat is omitted', function () {
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');
    app(Vault::class)->set('azdo-repos.pat', null, 'system-pat');

    app()->bind(CodesearchClientFactory::class, function () {
        $payload = ['count' => 0, 'results' => []];

        return new class($payload) extends CodesearchClientFactory
        {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload) {}

            public function make(string $organization, string $pat): CodesearchClient
            {
                expect($pat)->toBe('system-pat');

                return new CodesearchClient($organization, $pat, new Client([
                    'handler' => new MockHandler([
                        new Response(200, [], json_encode($this->payload, JSON_THROW_ON_ERROR)),
                    ]),
                ]));
            }
        };
    });

    $this->artisan('triage:codesearch', ['search' => 'openssl'])
        ->assertSuccessful();
});

it('runs code search and attaches the json payload to an alert', function () {
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    app()->bind(CodesearchClientFactory::class, function () {
        $payload = [
            'count' => 1,
            'results' => [[
                'project' => ['name' => 'SecurityProject'],
                'repository' => ['name' => 'backend-api'],
                'path' => '/src/Auth/LoginController.php',
                'hits' => [['line' => 18]],
            ]],
        ];

        return new class($payload) extends CodesearchClientFactory
        {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload) {}

            public function make(string $organization, string $pat): CodesearchClient
            {
                return new CodesearchClient($organization, $pat, new Client([
                    'handler' => new MockHandler([
                        new Response(200, [], json_encode($this->payload, JSON_THROW_ON_ERROR)),
                    ]),
                ]));
            }
        };
    });

    $event = SecurityEvent::factory()->create();

    $this->artisan('triage:codesearch', [
        'search' => 'openssl',
        '--pat' => 'top-secret-pat',
        '--scope' => 'project:SecurityProject',
        '--attach-to' => $event->id,
    ])
        ->expectsOutputToContain('Found 1 code search results.')
        ->expectsOutputToContain('Attached code search JSON to alert')
        ->assertSuccessful();

    $attachment = EventAttachment::query()->first();
    $audit = AuditLog::query()->where('action', 'triage_run')->first();

    expect($attachment)->not()->toBeNull()
        ->and($attachment?->kind)->toBe('codesearch-json')
        ->and($attachment?->mime)->toBe('application/json')
        ->and($attachment?->created_by_command)->toBe('triage:codesearch')
        ->and($attachment?->payload)->toContain('SecurityProject')
        ->and($audit)->not()->toBeNull()
        ->and($audit?->payload_json)->not()->toContain('top-secret-pat');
});

it('rejects invalid scope values', function () {
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    $this->artisan('triage:codesearch', [
        'search' => 'openssl',
        '--pat' => 'top-secret-pat',
        '--scope' => 'invalid',
    ])
        ->expectsOutputToContain('Scope must use the format project:<name> or repo:<name>.')
        ->assertExitCode(1);
});
