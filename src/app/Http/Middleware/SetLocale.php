<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale. Two sources, in priority order:
 *
 *   1. The authenticated user's explicit `locale` (their stored preference).
 *   2. The Accept-Language header — used for UNAUTHENTICATED requests such as
 *      /login and /2fa/validate (a failed login has no user, so without this the
 *      422 "invalid credentials" message would always render in the app default
 *      locale regardless of the SPA's UI language).
 *
 * Anything not in config('crm.locale.supported') is ignored, so the app default
 * (config/app.php / config/crm.php) stands. Header parsing is whitelist-only —
 * we never call app()->setLocale() with arbitrary client input.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * The supported locale to apply, or null to leave the app default in place.
     */
    private function resolveLocale(Request $request): ?string
    {
        /** @var list<string> $supported */
        $supported = array_values((array) config('crm.locale.supported', ['ru', 'en']));

        $user = $request->user();
        if ($user && $user->locale && in_array($user->locale, $supported, strict: true)) {
            return $user->locale;
        }

        // Unauthenticated (or user without a stored preference): honor the SPA's
        // language via Accept-Language, restricted to the supported whitelist.
        $preferred = $request->getPreferredLanguage($supported);

        return in_array($preferred, $supported, strict: true) ? $preferred : null;
    }
}
