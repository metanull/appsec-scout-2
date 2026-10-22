<?php

namespace App\Mcp\Support;

use App\Mcp\McpActor;
use App\Mcp\Servers\AppSecScoutServer;
use App\Models\User;
use RuntimeException;

/**
 * The ready-to-paste client configuration that starts the stdio MCP server as one user: a
 * `docker compose exec` into this stack's `app` container (see docs/mcp.md). It addresses the
 * stack by its Compose project name, so it works from any directory on the machine that runs
 * the stack.
 */
final class McpClientConfig
{
    private const APP_SERVICE = 'app';

    /** Characters that need no quoting in bash, zsh or PowerShell. */
    private const PLAIN_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@%+=:,./_-';

    /** @return list<string> */
    public function dockerArguments(User $user): array
    {
        McpActor::ensureEnabled($user);

        return [
            'compose', '--project-name', $this->composeProject(),
            'exec', '-T', '-e', McpActor::ENV . '=' . $user->email,
            self::APP_SERVICE, 'php', '-d', 'display_errors=stderr', 'artisan', 'mcp:start', AppSecScoutServer::HANDLE,
        ];
    }

    /** @return array{mcpServers: array<string, array{command: string, args: list<string>}>} */
    public function desktopConfig(User $user): array
    {
        return [
            'mcpServers' => [
                AppSecScoutServer::HANDLE => [
                    'command' => 'docker',
                    'args' => $this->dockerArguments($user),
                ],
            ],
        ];
    }

    public function desktopConfigJson(User $user): string
    {
        return json_encode($this->desktopConfig($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function claudeCodeCommand(User $user): string
    {
        $words = ['claude', 'mcp', 'add', AppSecScoutServer::HANDLE, '--scope', 'user', '--', 'docker', ...$this->dockerArguments($user)];

        return implode(' ', array_map($this->quote(...), $words));
    }

    /** The command that prints this configuration for $user, run on the machine hosting the stack. */
    public function printCommand(User $user): string
    {
        $words = ['docker', 'compose', '--project-name', $this->composeProject(), 'exec', '-T', self::APP_SERVICE, 'php', 'artisan', 'mcp:client-config', $user->email];

        return implode(' ', array_map($this->quote(...), $words));
    }

    private function composeProject(): string
    {
        $project = (string) config('mcp_client.compose_project');

        if ($project === '') {
            throw new RuntimeException('MCP_CLIENT_COMPOSE_PROJECT is not set, so the app cannot tell which Docker Compose project it runs in. docker-compose.yml sets it from ${COMPOSE_PROJECT_NAME}: recreate the app container with the current docker-compose.yml.');
        }

        return $project;
    }

    /**
     * Quotes a word the same way for bash, zsh and PowerShell: single quotes when it has none,
     * double quotes when it has single quotes but nothing either shell expands inside them.
     */
    private function quote(string $word): string
    {
        if ($word !== '' && strspn($word, self::PLAIN_CHARACTERS) === strlen($word)) {
            return $word;
        }

        if (! str_contains($word, "'")) {
            return "'{$word}'";
        }

        if (strpbrk($word, '$`"\\') === false) {
            return "\"{$word}\"";
        }

        throw new RuntimeException("{$word} cannot be quoted the same way for bash and PowerShell; use the JSON configuration instead.");
    }
}
