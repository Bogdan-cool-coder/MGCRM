<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Http\Controllers\Controller;
use App\Http\Resources\Contracts\DocumentRevisionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRevisionController extends Controller
{
    /**
     * GET /api/documents/{document}/revisions
     */
    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return DocumentRevisionResource::collection(
            $document->revisions()->with('createdBy:id,full_name')->get()
        );
    }

    /**
     * GET /api/documents/{document}/revisions/{revision}
     */
    public function show(Document $document, DocumentRevision $revision): JsonResource
    {
        $this->authorize('view', $document);

        // Scope guard: revision must belong to this document.
        abort_unless((int) $revision->document_id === $document->id, 404);

        return DocumentRevisionResource::make($revision->load('createdBy:id,full_name'));
    }
}
