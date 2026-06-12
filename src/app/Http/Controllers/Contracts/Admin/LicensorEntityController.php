<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts\Admin;

use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Services\LicensorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreLicensorEntityRequest;
use App\Http\Requests\Contracts\UpdateLicensorEntityRequest;
use App\Http\Resources\Contracts\LicensorEntityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class LicensorEntityController extends Controller
{
    public function __construct(
        private readonly LicensorService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LicensorEntity::class);

        $entities = LicensorEntity::query()
            ->with('bankAccounts')
            ->orderBy('country_code')
            ->get();

        return LicensorEntityResource::collection($entities);
    }

    public function show(Request $request, LicensorEntity $licensorEntity): JsonResource
    {
        $this->authorize('view', $licensorEntity);

        return LicensorEntityResource::make($licensorEntity->load('bankAccounts'));
    }

    public function store(StoreLicensorEntityRequest $request): JsonResponse
    {
        $entity = $this->service->create($request->validated());

        return LicensorEntityResource::make($entity->load('bankAccounts'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateLicensorEntityRequest $request, LicensorEntity $licensorEntity): JsonResource
    {
        $entity = $this->service->update($licensorEntity, $request->validated());

        return LicensorEntityResource::make($entity->load('bankAccounts'));
    }
}
