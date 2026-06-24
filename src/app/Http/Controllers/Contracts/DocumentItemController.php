<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Contracts\Services\DocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreDocumentItemRequest;
use App\Http\Requests\Contracts\UpdateDocumentItemRequest;
use App\Http\Resources\Contracts\DocumentItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentItemController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
    ) {}

    /**
     * GET /api/documents/{document}/items
     */
    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return DocumentItemResource::collection(
            $document->items()->get()
        );
    }

    /**
     * POST /api/documents/{document}/items
     */
    public function store(StoreDocumentItemRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $item = $this->service->addItem($document, $request->validated());

        return DocumentItemResource::make($item)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/documents/{document}/items/{item}
     */
    public function update(
        UpdateDocumentItemRequest $request,
        Document $document,
        DocumentItem $item,
    ): JsonResource {
        $this->authorize('update', $document);

        abort_unless((int) $item->document_id === $document->id, 404);

        $updated = $this->service->updateItem($document, $item, $request->validated());

        return DocumentItemResource::make($updated);
    }

    /**
     * DELETE /api/documents/{document}/items/{item}
     */
    public function destroy(Document $document, DocumentItem $item): JsonResponse
    {
        $this->authorize('update', $document);

        abort_unless((int) $item->document_id === $document->id, 404);

        $this->service->deleteItem($document, $item);

        return response()->json(null, 204);
    }
}
