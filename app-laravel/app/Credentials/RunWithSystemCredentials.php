<?php

namespace App\Credentials;

use Closure;

/**
 * Job and queued-listener middleware: runs the handler as the system credential owner
 * (`Vault::runAsOwner(null)`), so every `Vault::get()` it makes, directly or through a Source,
 * Tracker or Source Control provider, reads the system credential.
 *
 * Background work is system-triggered by definition, so this also stops a job run synchronously
 * inside a user's owner scope from inheriting that user's credentials. It is declared per job on
 * purpose: a blanket "console means system" default would bring the implicit default back for
 * every queue worker.
 *
 * Stateless, because a queued listener's middleware is serialized into the queue payload.
 */
final class RunWithSystemCredentials
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(Vault::class)->runAsOwner(null, fn (): mixed => $next($job));
    }
}
