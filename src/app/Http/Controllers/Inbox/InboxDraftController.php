<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\InboxDraft;
use App\Domain\Inbox\Services\InboxDraftService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\StoreInboxDraftRequest;
use App\Http\Requests\Inbox\UpdateInboxDraftRequest;
use App\Http\Resources\Inbox\InboxDraftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inbox drafts (СРЕЗ B, contract §4.6) — per-author CRUD of unsent reply notes.
 * The only per-user Inbox entity; scope is enforced by InboxDraftPolicy AND
 * InboxDraftService::list() (which only ever returns the caller's own rows).
 */
class InboxDraftController extends Controller
{
    public function __construct(private readonly InboxDraftService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InboxDraft::class);

        return InboxDraftResource::collection($this->service->list($request->user()));
    }

    public function store(StoreInboxDraftRequest $request): JsonResponse
    {
        $draft = $this->service->create($request->user(), $request->validated());

        return InboxDraftResource::make($draft)->response()->setStatusCode(201);
    }

    public function show(Request $request, InboxDraft $draft): JsonResource
    {
        $this->authorize('view', $draft);

        return InboxDraftResource::make($draft->load('relatedMessage'));
    }

    public function update(UpdateInboxDraftRequest $request, InboxDraft $draft): JsonResource
    {
        $draft = $this->service->update($draft, $request->validated());

        return InboxDraftResource::make($draft);
    }

    public function destroy(Request $request, InboxDraft $draft): JsonResponse
    {
        $this->authorize('delete', $draft);

        $this->service->delete($draft);

        return response()->json(null, 204);
    }
}
