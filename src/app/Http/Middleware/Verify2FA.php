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
 *
 * Active-account gate (Э2): even a valid full token is rejected with 403 when
 * the account has since been deactivated. UserService::deactivate() already
 * revokes the user's tokens, so this is defense-in-depth: it closes the tiny
 * window where a token could survive (e.g. a token minted concurrently, or a
 * future flow that flips is_active without going through the service). It lives
 * here — the existing per-request full-login gate — rather than as a new
 * middleware, so the protected group gains no extra alias and the check runs
 * exactly once on the same resolved user (least-invasive, ARCHITECTURE.md §1).
 */
class Verify2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token === null) {
            abort(401, __('auth.unauthenticated'));
        }

        // Full tokens carry '*'. The pre-2FA temp token does not -> blocked here.
        if (! $token->can('*')) {
            abort(403, __('auth.two_factor_required'));
        }

        // Deactivated mid-session: the token may still exist for a beat after a
        // concurrent deactivate() — reject it here so no request runs as an
        // inactive user.
        if (! $user->is_active) {
            abort(403, __('auth.inactive'));
        }

        return $next($request);
    }
}
