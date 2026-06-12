<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreDocumentRequest;
use App\Http\Requests\Contracts\SubmitDocumentRequest;
use App\Http\Requests\Contracts\UpdateDocumentRequest;
use App\Http\Resources\Contracts\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
    ) {}

    /**
     * GET /api/documents
     * Query: status, kind, product_code, country_code, author_id, archived, per_page
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $documents = $this->service->list($request->query(), $perPage);

        return DocumentResource::collection($documents);
    }

    /**
     * GET /api/documents/{document}
     */
    public function show(Document $document): JsonResource
    {
        $this->authorize('view', $document);

        return DocumentResource::make(
            $document->load(['items', 'revisions', 'attachments', 'remarks', 'author:id,full_name'])
        );
    }

    /**
     * POST /api/documents
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $doc = $this->service->create(
            $request->validated(),
            $request->user()->id,
        );

        return DocumentResource::make($doc)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/documents/{document}
     */
    public function update(UpdateDocumentRequest $request, Document $document): JsonResource
    {
        $this->authorize('update', $document);

        $updated = $this->service->update($document, $request->validated());

        return DocumentResource::make($updated);
    }

    /**
     * DELETE /api/documents/{document}
     * Only admin; only Draft documents.
     */
    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        // Service-level guard: can only delete Draft documents.
        if ($document->status->value !== 'draft') {
            abort(422, 'Only Draft documents may be physically deleted.');
        }

        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/documents/{document}/submit
     * Triggers Draft → Submitted transition with a revision snapshot.
     */
    public function submit(SubmitDocumentRequest $request, Document $document): JsonResource
    {
        $this->authorize('submit', $document);

        $updated = $this->service->transition(
            $document,
            ContractStatus::Submitted,
            $request->user()->id,
            $request->validated('note'),
        );

        return DocumentResource::make($updated);
    }

    /**
     * POST /api/documents/{document}/upload-drive
     * Stub — 409 not_yet_implemented until M11.
     */
    public function uploadDrive(Document $document): never
    {
        $this->authorize('uploadDrive', $document);

        $this->service->stubUploadDrive();
    }
}
