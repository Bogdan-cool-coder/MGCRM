<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Enums\SavedViewEntity;
use App\Domain\Crm\Models\SavedView;
use App\Domain\Crm\Services\SavedViewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSavedViewRequest;
use App\Http\Requests\Crm\UpdateSavedViewRequest;
use App\Http\Resources\Crm\SavedViewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * SavedViewController — server-persisted list view presets.
 * Routes under /crm/saved-views
 *
 * GET    /crm/saved-views?entity_type=contact|company  — list (own + shared)
 * POST   /crm/saved-views                              — create
 * PATCH  /crm/saved-views/{savedView}                  — update
 * DELETE /crm/saved-views/{savedView}                  — destroy
 * POST   /crm/saved-views/{savedView}/default          — set as default
 */
class SavedViewController extends Controller
{
    public function __construct(
        private readonly SavedViewService $service,
    ) {}

    /**
     * GET /crm/saved-views?entity_type=contact|company
     * Returns own personal views + all shared views.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SavedView::class);

        $entity = SavedViewEntity::from($request->query('entity_type', 'contact'));

        $views = $this->service->list($request->user(), $entity);

        return SavedViewResource::collection($views);
    }

    /**
     * POST /crm/saved-views
     */
    public function store(StoreSavedViewRequest $request): JsonResponse
    {
        $this->authorize('create', SavedView::class);

        $data = $request->validated();
        $view = $this->service->create(
            $request->user(),
            SavedViewEntity::from($data['entity_type']),
            $data['name'],
            (bool) ($data['is_shared'] ?? false),
            (bool) ($data['is_default'] ?? false),
            $data['payload'],
        );

        return (new SavedViewResource($view))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * PATCH /crm/saved-views/{savedView}
     */
    public function update(UpdateSavedViewRequest $request, SavedView $savedView): SavedViewResource
    {
        $this->authorize('update', $savedView);

        $data = $request->validated();
        $view = $this->service->update(
            $savedView,
            $data['name'],
            (bool) ($data['is_shared'] ?? $savedView->is_shared),
            (bool) ($data['is_default'] ?? $savedView->is_default),
            $data['payload'],
        );

        return new SavedViewResource($view);
    }

    /**
     * DELETE /crm/saved-views/{savedView}
     */
    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorize('delete', $savedView);

        $this->service->delete($savedView);

        return response()->json(null, 204);
    }

    /**
     * POST /crm/saved-views/{savedView}/default
     * Mark view as the calling user's default for its entity_type.
     * Any authenticated user can pin a shared view as their own default.
     */
    public function setDefault(Request $request, SavedView $savedView): SavedViewResource
    {
        $this->authorize('view', $savedView);

        $view = $this->service->setDefault($request->user(), $savedView);

        return new SavedViewResource($view);
    }
}
