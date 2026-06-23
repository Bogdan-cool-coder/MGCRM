<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CrmFile;
use App\Domain\Crm\Models\CrmFolder;
use App\Domain\Crm\Services\CrmFileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UploadFileRequest;
use App\Http\Resources\Crm\CrmFileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CrmFileController — file sub-resource under entity folders.
 *
 * Routes (mirrored under companies and contacts):
 *   GET    companies/{company}/folders/{folder}/files
 *   POST   companies/{company}/folders/{folder}/files
 *   GET    companies/{company}/files/{file}/download
 *   DELETE companies/{company}/files/{file}
 *   GET    contacts/{contact}/folders/{folder}/files
 *   POST   contacts/{contact}/folders/{folder}/files
 *   GET    contacts/{contact}/files/{file}/download
 *   DELETE contacts/{contact}/files/{file}
 *
 * "Сканы договоров" folder:
 *   - GET returns document DTOs (raw JSON array, not CrmFileResource collection)
 *   - POST → 422
 */
class CrmFileController extends Controller
{
    public function __construct(
        private readonly CrmFileService $service,
    ) {}

    // ==== company routes ====

    /**
     * GET /companies/{company}/folders/{folder}/files
     */
    public function indexForCompany(Request $request, Company $company, CrmFolder $folder): JsonResponse|AnonymousResourceCollection
    {
        $this->authorize('view', $company);
        $this->assertFolderBelongsTo($folder, 'company', $company->id);

        $files = $this->service->listFilesInFolder($folder, $company);

        if ($folder->isScansFolder()) {
            // $files is array of raw DTOs — return as plain JSON.
            return response()->json(['data' => $files]);
        }

        return CrmFileResource::collection($files); /** @phpstan-ignore-line */
    }

    /**
     * POST /companies/{company}/folders/{folder}/files
     */
    public function uploadForCompany(UploadFileRequest $request, Company $company, CrmFolder $folder): JsonResponse
    {
        $this->assertFolderBelongsTo($folder, 'company', $company->id);

        $file = $this->service->uploadFile($folder, $request->file('file'), $request->user());

        return CrmFileResource::make($file)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /companies/{company}/files/{file}/download
     */
    public function downloadForCompany(Request $request, Company $company, CrmFile $file): StreamedResponse
    {
        $this->authorize('view', $company);
        $this->assertFileBelongsTo($file, 'company', $company->id);

        return $this->service->download($file);
    }

    /**
     * DELETE /companies/{company}/files/{file}
     */
    public function destroyForCompany(Request $request, Company $company, CrmFile $file): JsonResponse
    {
        $this->authorize('update', $company);
        $this->assertFileBelongsTo($file, 'company', $company->id);

        $this->service->deleteFile($file);

        return response()->json(['message' => 'File deleted.']);
    }

    // ==== contact routes ====

    /**
     * GET /contacts/{contact}/folders/{folder}/files
     */
    public function indexForContact(Request $request, Contact $contact, CrmFolder $folder): JsonResponse|AnonymousResourceCollection
    {
        $this->authorize('view', $contact);
        $this->assertFolderBelongsTo($folder, 'contact', $contact->id);

        $files = $this->service->listFilesInFolder($folder, $contact);

        // Contact can never have "Сканы договоров" — but guard defensively.
        if ($folder->isScansFolder()) {
            return response()->json(['data' => []]);
        }

        return CrmFileResource::collection($files); /** @phpstan-ignore-line */
    }

    /**
     * POST /contacts/{contact}/folders/{folder}/files
     */
    public function uploadForContact(UploadFileRequest $request, Contact $contact, CrmFolder $folder): JsonResponse
    {
        $this->assertFolderBelongsTo($folder, 'contact', $contact->id);

        $file = $this->service->uploadFile($folder, $request->file('file'), $request->user());

        return CrmFileResource::make($file)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /contacts/{contact}/files/{file}/download
     */
    public function downloadForContact(Request $request, Contact $contact, CrmFile $file): StreamedResponse
    {
        $this->authorize('view', $contact);
        $this->assertFileBelongsTo($file, 'contact', $contact->id);

        return $this->service->download($file);
    }

    /**
     * DELETE /contacts/{contact}/files/{file}
     */
    public function destroyForContact(Request $request, Contact $contact, CrmFile $file): JsonResponse
    {
        $this->authorize('update', $contact);
        $this->assertFileBelongsTo($file, 'contact', $contact->id);

        $this->service->deleteFile($file);

        return response()->json(['message' => 'File deleted.']);
    }

    // ---- Private guards ----

    private function assertFolderBelongsTo(CrmFolder $folder, string $type, int $id): void
    {
        abort_if(
            $folder->owner_entity_type !== $type || (int) $folder->owner_entity_id !== $id,
            404,
        );
    }

    private function assertFileBelongsTo(CrmFile $file, string $type, int $id): void
    {
        abort_if(
            $file->owner_entity_type !== $type || (int) $file->owner_entity_id !== $id,
            404,
        );
    }
}
