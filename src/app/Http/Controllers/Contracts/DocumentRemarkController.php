<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Contracts\Services\RemarkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreDocumentRemarkRequest;
use App\Http\Resources\Contracts\DocumentRemarkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRemarkController extends Controller
{
    public function __construct(
        private readonly RemarkService $service,
    ) {}

    /**
     * GET /api/documents/{document}/remarks
     * Query: ?attempt=N — filter by attempt; omit to return all.
     */
    public function index(Request $request, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $attempt = $request->query('attempt') !== null
            ? (int) $request->query('attempt')
            : null;

        $remarks = $this->service->listForDocument($document, $attempt);

        return DocumentRemarkResource::collection($remarks);
    }

    /**
     * POST /api/documents/{document}/remarks
     * Admin/lawyer only. Primary path is S2.6 ApprovalService.
     */
    public function store(StoreDocumentRemarkRequest $request, Document $document): JsonResponse
    {
        $this->authorize('createRemark', $document);

        $remark = $this->service->create(
            $document,
            $request->user(),
            $request->validated('text'),
        );

        return DocumentRemarkResource::make($remark->load(['author:id,full_name']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/documents/{document}/remarks/{remark}/resolve
     * Toggle is_resolved. Author, admin, or lawyer.
     */
    public function toggleResolve(Request $request, Document $document, DocumentRemark $remark): JsonResource
    {
        $this->authorize('resolveRemark', $document);

        abort_unless((int) $remark->document_id === $document->id, 404);

        $updated = $this->service->toggleResolve($remark, $request->user());

        return DocumentRemarkResource::make($updated->load(['author:id,full_name', 'resolvedBy:id,full_name']));
    }
}
