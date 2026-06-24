<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Services\AttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\UploadAttachmentRequest;
use App\Http\Resources\Contracts\DocumentAttachmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $service,
    ) {}

    /**
     * GET /api/documents/{document}/attachments
     */
    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $attachments = $this->service->listForDocument($document)
            ->load('uploadedBy:id,full_name');

        return DocumentAttachmentResource::collection($attachments);
    }

    /**
     * POST /api/documents/{document}/attachments
     * Multipart upload. Author, admin, or lawyer.
     */
    public function store(UploadAttachmentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('uploadAttachment', $document);

        $kind = AttachmentKind::from($request->validated('kind'));

        $attachment = $this->service->upload(
            $document,
            $request->file('file'),
            $kind,
            $request->user(),
        );

        $attachment->setRelation('uploadedBy', $request->user());

        return DocumentAttachmentResource::make($attachment)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/documents/{document}/attachments/{attachment}/download
     * Returns a streamed file response.
     */
    public function download(Document $document, DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $document);

        // IDOR guard: attachment must belong to the route-bound document.
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);

        return $this->service->download($attachment);
    }

    /**
     * DELETE /api/documents/{document}/attachments/{attachment}
     * Guard: forbidden if document is signed.
     */
    public function destroy(Document $document, DocumentAttachment $attachment): JsonResponse
    {
        $this->authorize('deleteAttachment', $document);

        // IDOR guard: attachment must belong to the route-bound document.
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);

        $this->service->delete($document, $attachment);

        return response()->json(null, 204);
    }
}
