<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    | MGCRM is a Bearer personal-access-token API (the SPA stores the token; no
    | cookie/session SPA auth). The web-guard fallback is intentionally DISABLED:
    | with it on, a stale web session makes Sanctum return a TransientToken whose
    | abilities are all-true, which would silently bypass per-token ability gates
    | (e.g. the 2FA temp-token restriction in the Verify2FA middleware). Empty
    | guard list forces authentication purely from the Bearer token so token
    | abilities are always honored.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    | MGCRM is a Bearer-token API whose SPA stores the token; tokens MUST NOT
    | silently expire mid-session. The default is null (never expires) — the
    | only correct production value. SANCTUM_TOKEN_EXPIRATION exists purely as a
    | deliberate escape hatch; a BLANK or unset env var coerces to null here (an
    | empty string would otherwise read as a truthy non-null config value and is
    | the kind of foot-gun that expires every token after 0 minutes). Set a
    | positive integer only if a finite TTL is ever genuinely wanted.
    |
    */

    'expiration' => ($sanctumTtl = env('SANCTUM_TOKEN_EXPIRATION')) !== null && $sanctumTtl !== ''
        ? (int) $sanctumTtl
        : null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
