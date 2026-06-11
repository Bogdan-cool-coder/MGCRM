<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the authenticated user's preferred locale to the request. RU is the
 * app default (config/app.php / config/crm.php); this only switches when a user
 * has an explicit locale. Copied 1-to-1 from the Vizion pattern.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->locale) {
            app()->setLocale($request->user()->locale);
        }

        return $next($request);
    }
}
