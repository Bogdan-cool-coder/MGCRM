<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company id for the authenticated request and stores it
 * in $request->attributes under 'active_company_id'. Downstream controllers
 * and services (Reports / Chats / ReportDataService) should read it from there
 * instead of falling back to $user->company_id directly — that keeps the
 * "currently selected company" concept in one place.
 *
 * Falls back to home company_id when the user has no active selection or
 * when their stored active_company_id has been revoked (see
 * User::resolveActiveCompanyId()).
 */
class ResolveActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $request->attributes->set('active_company_id', $user->resolveActiveCompanyId());
        }

        return $next($request);
    }
}
