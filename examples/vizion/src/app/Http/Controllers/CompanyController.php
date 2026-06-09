<?php

namespace App\Http\Controllers;

use App\Models\Company;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    /**
     * Build the validation rules for currency_code (ISO 4217, 3 letters, A-Z)
     * and timezone (IANA name, validated against PHP's full list).
     *
     * Kept centralised so store() and update() can't drift. We deliberately do
     * NOT enforce a whitelist of currency codes — the project trusts the UI to
     * surface a sensible picker (RUB / USD / EUR / KZT / etc.) and the column
     * is short enough that a bad code is a UI bug, not a security risk.
     */
    private function currencyAndTimezoneRules(): array
    {
        return [
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone'      => ['nullable', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }

    public function index(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            $companyIds = collect($request->user()->company_accesses ?? [])
                ->pluck('company_id');

            return Company::whereIn('id', $companyIds)->get();
        }

        return Company::all();
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'crm_url' => 'nullable|url|max:255',
            'macrodata_host' => 'nullable|string|max:255',
            'macrodata_port' => 'nullable|integer',
            'macrodata_database' => 'nullable|string|max:255',
            'macrodata_username' => 'nullable|string|max:255',
            'macrodata_password' => 'nullable|string|max:255',
        ] + $this->currencyAndTimezoneRules());

        return Company::create($data);
    }

    public function show(Request $request, Company $company)
    {
        $user = $request->user();

        if ($user->role !== 'superadmin') {
            $hasAccess = collect($user->company_accesses ?? [])
                ->contains('company_id', $company->id);

            if (!$hasAccess) {
                return response()->json(['message' => __('auth.forbidden')], 403);
            }
        }

        return $company;
    }

    public function update(Request $request, Company $company)
    {
        $user = $request->user();

        // superadmin: any company.
        // admin: only their own company (must have access via company_accesses).
        // analyst / viewer: never.
        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && $user->canAccessCompany($company->id));

        if (!$allowed) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        // Non-superadmins can only touch the "soft" company profile fields
        // (currency, timezone, display fields). MacroData credentials and the
        // company name stay superadmin-only to prevent an admin from rerouting
        // their own company's data connection.
        if ($user->role === 'superadmin') {
            $rules = [
                'name' => 'sometimes|string|max:255',
                'crm_url' => 'nullable|url|max:255',
                'macrodata_host' => 'nullable|string|max:255',
                'macrodata_port' => 'nullable|integer',
                'macrodata_database' => 'nullable|string|max:255',
                'macrodata_username' => 'nullable|string|max:255',
                'macrodata_password' => 'nullable|string|max:255',
            ] + $this->currencyAndTimezoneRules();
        } else {
            $rules = [
                'crm_url' => 'nullable|url|max:255',
            ] + $this->currencyAndTimezoneRules();
        }

        $data = $request->validate($rules);

        $company->update($data);

        return $company;
    }

    public function destroy(Request $request, Company $company)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if ($company->is_system) {
            return response()->json(['message' => __('companies.cannot_delete_system')], 403);
        }

        $company->delete();

        return response()->json(['message' => __('companies.deleted')]);
    }
}
