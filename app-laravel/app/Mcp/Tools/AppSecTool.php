<?php

namespace App\Mcp\Tools;

use App\Audit\AuditChannel;
use App\Mcp\McpActor;
use App\Mcp\McpActorException;
use App\Models\ErrorLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base class for every AppSec Scout MCP tool. The MCP server is a user-facing surface, so a
 * tool runs as the acting user (McpActor), synchronously, and checks the same Spatie
 * permission the equivalent Filament action checks — the services it calls do not, by design.
 * Audit entries written during the call are tagged `channel: mcp`.
 */
abstract class AppSecTool extends Tool
{
    /** @return list<string> abilities the acting user must hold to call this tool */
    protected function requiredPermissions(): array
    {
        return [];
    }

    abstract protected function run(Request $request, User $actor): Response|ResponseFactory;

    final public function handle(Request $request, McpActor $actors, AuditChannel $channel): Response|ResponseFactory
    {
        try {
            $actor = $this->actor($request, $actors);

            Auth::setUser($actor);
            $channel->set('mcp');

            foreach ($this->requiredPermissions() as $ability) {
                if (! $actor->can($ability)) {
                    return self::permissionDenied($ability);
                }
            }

            return $this->run($request, $actor);
        } catch (ValidationException|AuthorizationException $e) {
            // Rendered as tool errors by laravel/mcp itself (validation messages kept intact).
            throw $e;
        } catch (McpActorException|InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            $this->logFailure($e, $request);

            return Response::error($e->getMessage());
        }
    }

    public static function permissionDenied(string $ability): Response
    {
        return Response::error("Permission denied: this tool requires the {$ability} permission.");
    }

    private function actor(Request $request, McpActor $actors): User
    {
        $user = $request->user();

        if ($user instanceof User) {
            McpActor::ensureEnabled($user);

            return $user;
        }

        return $actors->resolve();
    }

    private function logFailure(Throwable $e, Request $request): void
    {
        $context = ['tool' => $this->name(), 'user_id' => Auth::id()];

        // Not Log::error(): the default stack already includes the database handler at level
        // error, which would add a second ErrorLog row without the `mcp` channel.
        Log::channel('single')->error($e->getMessage(), $context + ['exception' => $e]);

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'mcp',
            'message' => $e->getMessage(),
            'context_json' => $context + ['arguments' => array_keys($request->all())],
            'trace' => $e->getTraceAsString(),
            'occurred_at' => now(),
        ]);
    }
}
