<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves the user the stdio MCP server acts as. The server is launched per client session
 * with `docker compose exec -e MCP_USER_EMAIL=... app php artisan mcp:start appsec-scout`, so
 * the email is read from the process environment with getenv(): env() only sees values that
 * existed when the configuration was loaded, which is wrong under `config:cache`.
 *
 * There is deliberately no login or TOTP step: whoever can `docker exec` into the stack already
 * controls it. Permissions still come from the user's role, and disabled users are refused.
 */
final class McpActor
{
    public const ENV = 'MCP_USER_EMAIL';

    private ?User $user = null;

    public function resolve(): User
    {
        $resolved = $this->user;

        if ($resolved !== null) {
            // Re-read so a user disabled while the server keeps running is refused.
            $resolved->refresh();
            self::ensureEnabled($resolved);

            return $resolved;
        }

        $email = getenv(self::ENV);

        if (! is_string($email) || trim($email) === '') {
            throw new McpActorException(self::ENV . ' is not set. Pass it to the MCP server process, e.g. `docker compose exec -T -e ' . self::ENV . '=you@example.com app php artisan mcp:start appsec-scout`.');
        }

        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw new McpActorException("No user with email {$email}.");
        }

        self::ensureEnabled($user);

        return $this->user = $user;
    }

    public static function ensureEnabled(User $user): void
    {
        if ($user->is_disabled) {
            throw new McpActorException("User {$user->email} is disabled.");
        }
    }
}
