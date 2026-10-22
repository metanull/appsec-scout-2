<?php

use App\Filament\Pages\ProfileIntegrationsPage;
use App\Mcp\McpActorException;
use App\Mcp\Support\McpClientConfig;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    config(['mcp_client.compose_project' => 'appsec-scout-2']);
});

function mcpClientUser(array $attributes = []): User
{
    return User::factory()->create([
        'email' => 'analyst@example.test',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
        ...$attributes,
    ]);
}

it('builds the Claude Desktop configuration that starts the stdio server as the user', function () {
    $config = app(McpClientConfig::class)->desktopConfig(mcpClientUser());

    expect($config)->toBe([
        'mcpServers' => [
            'appsec-scout' => [
                'command' => 'docker',
                'args' => [
                    'compose', '--project-name', 'appsec-scout-2',
                    'exec', '-T', '-e', 'MCP_USER_EMAIL=analyst@example.test',
                    'app', 'php', '-d', 'display_errors=stderr', 'artisan', 'mcp:start', 'appsec-scout',
                ],
            ],
        ],
    ]);
});

it('builds the matching claude mcp add command', function () {
    expect(app(McpClientConfig::class)->claudeCodeCommand(mcpClientUser()))
        ->toBe('claude mcp add appsec-scout --scope user -- docker compose --project-name appsec-scout-2 exec -T -e MCP_USER_EMAIL=analyst@example.test app php -d display_errors=stderr artisan mcp:start appsec-scout');
});

it('quotes an email the same way for bash and PowerShell', function () {
    $config = app(McpClientConfig::class);

    expect($config->claudeCodeCommand(mcpClientUser(['email' => 'first last@example.test'])))->toContain(" 'MCP_USER_EMAIL=first last@example.test' ")
        ->and($config->claudeCodeCommand(mcpClientUser(['email' => "o'neill@example.test"])))->toContain(' "MCP_USER_EMAIL=o\'neill@example.test" ');
});

it('refuses to build a configuration when the compose project is unknown', function () {
    config(['mcp_client.compose_project' => null]);

    app(McpClientConfig::class)->desktopConfig(mcpClientUser());
})->throws(RuntimeException::class, 'MCP_CLIENT_COMPOSE_PROJECT is not set');

it('refuses to build a configuration for a disabled user', function () {
    app(McpClientConfig::class)->desktopConfig(mcpClientUser(['is_disabled' => true]));
})->throws(McpActorException::class, 'is disabled');

it('prints the Claude Desktop JSON from the command line', function () {
    mcpClientUser();

    $this->artisan('mcp:client-config', ['email' => 'Analyst@Example.test'])
        ->expectsOutput(app(McpClientConfig::class)->desktopConfigJson(User::query()->where('email', 'analyst@example.test')->firstOrFail()))
        ->assertSuccessful();
});

it('prints the claude mcp add command from the command line', function () {
    $user = mcpClientUser();

    $this->artisan('mcp:client-config', ['email' => 'analyst@example.test', '--format' => 'claude-code'])
        ->expectsOutput(app(McpClientConfig::class)->claudeCodeCommand($user))
        ->assertSuccessful();
});

it('fails clearly for an unknown user or format', function () {
    mcpClientUser();

    $this->artisan('mcp:client-config', ['email' => 'nobody@example.test'])
        ->expectsOutput('No user with email nobody@example.test.')
        ->assertFailed();

    $this->artisan('mcp:client-config', ['email' => 'analyst@example.test', '--format' => 'yaml'])
        ->expectsOutput('Unknown --format: use json or claude-code.')
        ->assertFailed();
});

it('shows the signed-in user\'s MCP client configuration on Profile integrations', function () {
    $user = mcpClientUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->assertSee('Claude (MCP server)')
        ->assertSee('MCP_USER_EMAIL=analyst@example.test')
        ->assertSee('claude mcp add appsec-scout --scope user')
        ->assertSee('artisan mcp:client-config analyst@example.test');
});

it('explains on Profile integrations why the configuration is unavailable', function () {
    config(['mcp_client.compose_project' => null]);

    Livewire::actingAs(mcpClientUser())
        ->test(ProfileIntegrationsPage::class)
        ->assertSee('Configuration unavailable')
        ->assertSee('MCP_CLIENT_COMPOSE_PROJECT is not set');
});
