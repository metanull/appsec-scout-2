<?php

use App\Http\Middleware\EnsureUserIsEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureUserIsEnabled::class,
        ]);
        // The `auth`-guarded download routes (routes/web.php, Filament's export download) send a
        // guest to the panel's sign-in page — the app defines no plain `login` route.
        $middleware->redirectGuestsTo(fn (): string => route('filament.appsec-scout.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
