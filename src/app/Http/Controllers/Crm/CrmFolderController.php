<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CrmFolder;
use App\Domain\Crm\Services\CrmFileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreFolderRequest;
use App\Http\Resources\Crm\CrmFolderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CrmFolderController — folder sub-resource on company and contact cards.
 *
 * Routes (mirrored under both prefixes):
 *   GET    companies/{company}/folders
 *   POST   companies/{company}/folders
 *   DELETE companies/{company}/folders/{folder}
 *   GET    contacts/{contact}/folders
 *   POST   contacts/{contact}/folders
 *   DELETE contacts/{contact}/folders/{folder}
 *
 * Authorization: inherits parent entity's 'view'/'update' via Policy.
 */
class CrmFolderController extends Controller
{
    public function __construct(
        private readonly CrmFileService $service,
    ) {}

    // ---- company routes ----

    /**
     * GET /companies/{company}/folders
     */
    public function indexForCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $folders = $this->service->listFolders($company);

        return CrmFolderResource::collection($folders);
    }

    /**
     * POST /companies/{company}/folders
     */
    public function storeForCompany(StoreFolderRequest $request, Company $company): JsonResponse
    {
        $folder = $this->service->createFolder($company, $request->validated());

        return CrmFolderResource::make($folder)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /companies/{company}/folders/{folder}
     */
    public function destroyForCompany(Request $request, Company $company, CrmFolder $folder): JsonResponse
    {
        $this->authorize('update', $company);
        abort_if((int) $folder->owner_entity_id !== $company->id || $folder->owner_entity_type !== 'company', 404);

        $this->service->deleteFolder($folder);

        return response()->json(['message' => 'Folder deleted.']);
    }

    // ---- contact routes ----

    /**
     * GET /contacts/{contact}/folders
     */
    public function indexForContact(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $folders = $this->service->listFolders($contact);

        return CrmFolderResource::collection($folders);
    }

    /**
     * POST /contacts/{contact}/folders
     */
    public function storeForContact(StoreFolderRequest $request, Contact $contact): JsonResponse
    {
        $folder = $this->service->createFolder($contact, $request->validated());

        return CrmFolderResource::make($folder)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /contacts/{contact}/folders/{folder}
     */
    public function destroyForContact(Request $request, Contact $contact, CrmFolder $folder): JsonResponse
    {
        $this->authorize('update', $contact);
        abort_if((int) $folder->owner_entity_id !== $contact->id || $folder->owner_entity_type !== 'contact', 404);

        $this->service->deleteFolder($folder);

        return response()->json(['message' => 'Folder deleted.']);
    }
}
