<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\BulkContactService;
use App\Domain\Crm\Services\ContactExportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BulkContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * ContactBulkController — mass operations on contacts.
 * Routes MUST be registered BEFORE apiResource('contacts') to avoid routing clash
 * (same pattern as deals/bulk: registered before apiResource deals).
 *
 * PATCH  /api/contacts/bulk   → apply
 * DELETE /api/contacts/bulk   → delete
 * POST   /api/contacts/export → export
 */
class ContactBulkController extends Controller
{
    public function __construct(
        private readonly BulkContactService $bulkService,
        private readonly ContactExportService $exportService,
    ) {}

    public function apply(BulkContactRequest $request): JsonResponse
    {
        try {
            $count = $this->bulkService->apply(
                $request->validated('contact_ids'),
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
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'min:1'],
        ]);

        try {
            $count = $this->bulkService->delete(
                $validated['contact_ids'],
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
        // could dump the full contacts table regardless of role (CRM-B2).
        $this->authorize('viewAny', Contact::class);

        $request->validate([
            'contact_ids' => ['sometimes', 'array'],
            'contact_ids.*' => ['integer', 'min:1'],
        ]);

        $contactIds = $request->input('contact_ids', []);

        // Pass the actor so the export service applies the same visibility scope
        // as the list endpoint — a manager can only export their own contacts.
        $xlsx = $this->exportService->buildXlsx($contactIds, $request->user());

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="contacts_export.xlsx"',
        ]);
    }
}
