<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Services\BulkCompanyService;
use App\Domain\Crm\Services\CompanyExportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BulkCompanyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CompanyBulkController — mass operations on companies.
 * Routes MUST be registered BEFORE apiResource('companies').
 *
 * PATCH  /api/companies/bulk   → apply
 * DELETE /api/companies/bulk   → delete
 * POST   /api/companies/export → export
 */
class CompanyBulkController extends Controller
{
    public function __construct(
        private readonly BulkCompanyService $bulkService,
        private readonly CompanyExportService $exportService,
    ) {}

    public function apply(BulkCompanyRequest $request): JsonResponse
    {
        try {
            $count = $this->bulkService->apply(
                $request->validated('company_ids'),
                $request->validated('operation'),
                $request->validated(),
                $request->user(),
            );
        } catch (AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => ['processed' => $count]]);
    }

    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'min:1'],
        ]);

        try {
            $count = $this->bulkService->delete(
                $validated['company_ids'],
                $request->user(),
            );
        } catch (AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => ['deleted' => $count]]);
    }

    public function export(Request $request): Response
    {
        // Require viewAny authorization — without this any authenticated user
        // could dump the full companies table regardless of role (CRM-B1).
        $this->authorize('viewAny', \App\Domain\Crm\Models\Company::class);

        $request->validate([
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['integer', 'min:1'],
        ]);

        $companyIds = $request->input('company_ids', []);

        // Pass the actor so the export service applies the same visibility scope
        // as the list endpoint — a manager can only export their own companies.
        $xlsx = $this->exportService->buildXlsx($companyIds, $request->user());

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="companies_export.xlsx"',
        ]);
    }
}
