<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp the request with the caller's row-level visibility scope (fail-closed).
 *
 * Resolves role -> scope via VisibilityResolver and attaches it as
 * `visibility_scope` on the request so a controller can read it without
 * re-resolving (Sales DealController/DealKpiController + ActivityController do).
 *
 * This is a CONVENIENCE carrier, not the enforcement point. The actual row
 * filtering happens in the domain services via VisibilityResolver::applyScope()
 * (or, for Deals, DealService::scopedQuery()) — a controller that does not pass
 * the scope down to its service gets NO scoping. New list/export services should
 * inject VisibilityResolver and call applyScope() directly rather than relying on
 * this attribute being read.
 *
 * Fail-closed: no authenticated user collapses to the most restrictive Own scope
 * rather than leaking.
 */
class ResolveVisibility
{
    public const ATTRIBUTE = 'visibility_scope';

    public function __construct(
        private readonly VisibilityResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $scope = $user instanceof User
            ? $this->resolver->resolve($user)
            : VisibilityScope::Own;

        $request->attributes->set(self::ATTRIBUTE, $scope);

        return $next($request);
    }
}
