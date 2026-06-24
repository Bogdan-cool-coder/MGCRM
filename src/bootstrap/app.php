<?php

use App\Http\Middleware\ResolveVisibility;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\Verify2FA;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'locale' => SetLocale::class,
            '2fa' => Verify2FA::class,
            'visibility' => ResolveVisibility::class,
            // Sanctum per-token ability gates are NOT auto-registered in
            // Laravel 12 — alias them so routes can use `ability:<scope>`
            // (e.g. /2fa/validate is restricted to the limited temp token).
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // NEW-4: this is an API-only app — there is no `login` named route. Without
        // this, the Authenticate middleware tries to redirect unauthenticated
        // requests to route('login') and throws RouteNotFoundException → a 500 with
        // a full stack trace (information disclosure). Returning null for api/*
        // (here: every request) makes the guard throw AuthenticationException
        // instead, which renders as a clean 401 JSON via the handler below.
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
