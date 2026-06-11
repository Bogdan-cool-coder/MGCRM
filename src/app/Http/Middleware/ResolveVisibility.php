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
 * M0 scaffold: resolves role -> scope via VisibilityResolver and attaches it as
 * `visibility_scope` on the request. Query filtering by this scope arrives in
 * M1 in each domain context. Fail-closed: no authenticated user collapses to the
 * most restrictive Own scope rather than leaking.
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
