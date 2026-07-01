<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\Tag;
use App\Domain\Crm\Services\TagService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreTagRequest;
use App\Http\Requests\Crm\UpdateTagRequest;
use App\Http\Resources\Crm\TagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin admin controller for the Tags directory.
 *
 * Read: any authenticated user (feeds autocomplete + filter dropdowns).
 * Write: admin/director only (admin-write gate).
 *
 * GET ?active_only=1  — only is_active=true rows (used by tag pickers).
 * GET ?scope=contact  — tags for that scope + universal tags (scope=null).
 * GET ?q=vip         — name search (autocomplete).
 */
class TagController extends Controller
{
    public function __construct(private readonly TagService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $activeOnly = $request->boolean('active_only');
        $scope = $request->string('scope')->toString() ?: null;
        $search = $request->string('q')->toString() ?: null;

        return TagResource::collection($this->service->list($activeOnly, $scope, $search));
    }

    public function show(Request $request, Tag $tag): JsonResource
    {
        return TagResource::make($tag);
    }

    public function store(StoreTagRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $tag = $this->service->create($request->validated());

        return TagResource::make($tag);
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResource
    {
        $this->authorize('admin-write');

        $tag = $this->service->update($tag, $request->validated());

        return TagResource::make($tag);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $this->authorize('admin-write');

        $this->service->delete($tag);

        return response()->json(['message' => 'Deleted.']);
    }
}
