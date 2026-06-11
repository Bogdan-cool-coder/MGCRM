<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the protected API behind a fully completed login.
 *
 * Token abilities encode the auth phase (see AuthService):
 *   ['2fa:validate'] — limited temp token issued at /login when 2FA is on; it
 *                      authenticates ONLY /2fa/validate (which is not behind this
 *                      middleware).
 *   ['*']            — full token, minted directly when 2FA is off or by
 *                      /2fa/validate after a valid second factor.
 *
 * The gate is fail-closed and ability-positive: a protected route requires the
 * full-access `*` ability. A request with no usable token -> 401; a request with
 * a token that lacks `*` (i.e. the pre-2FA temp token) -> 403. This rejects any
 * under-privileged token, not just the one specific temp ability, so a future
 * ability scheme cannot accidentally slip a partial token past the gate.
 */
class Verify2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            abort(401, __('auth.unauthenticated'));
        }

        // Full tokens carry '*'. The pre-2FA temp token does not -> blocked here.
        if (! $token->can('*')) {
            abort(403, __('auth.two_factor_required'));
        }

        return $next($request);
    }
}
