<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = $request->route('company_id') ?? $request->input('company_id');

        if (!$companyId) {
            return $next($request);
        }

        if ($user->role === 'superadmin') {
            return $next($request);
        }

        $accesses = $user->company_accesses ?? [];
        $hasAccess = collect($accesses)->contains('company_id', (int) $companyId);

        if (!$hasAccess) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        return $next($request);
    }
}
