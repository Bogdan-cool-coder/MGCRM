<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Switch the user's active company (the company currently selected in the UI).
 *
 * Server-side source of truth for the company picker. The frontend used to
 * pass ?company_id= on every request and keep its own choice in localStorage;
 * now the choice is persisted in users.active_company_id and resolved by the
 * ResolveActiveCompany middleware on every authenticated request.
 */
class ActiveCompanyController extends Controller
{
    public function switch(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $company = Company::find($id);

        if (! $company) {
            return response()->json(['message' => __('companies.not_found')], 404);
        }

        if (! $user->canAccessCompany($id)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $user->active_company_id = $id;
        $user->save();

        return response()->json($user->fresh()->load('company', 'activeCompany'));
    }
}
