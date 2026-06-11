<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\DedupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DismissDedupRequest;
use App\Http\Requests\Crm\MergeDedupRequest;
use App\Http\Resources\Crm\DedupCandidateResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * DedupController — scan / merge / dismiss duplicate CRM entities.
 * Routes: /crm/dedup/*
 *
 * Authorization: every participating entity (master, duplicates, dismiss pair)
 * is resolved and checked via the entity's own Policy (CompanyPolicy /
 * ContactPolicy). A Manager who cannot view/update a record gets 403.
 * Non-existent IDs produce 404 from findOrFail.
 */
class DedupController extends Controller
{
    public function __construct(
        private readonly DedupService $service,
    ) {}

    /**
     * GET /crm/dedup/scan?scope=contact|company[&entity_id=X]
     *
     * Without entity_id  → global scan: returns duplicate groups across the
     *                       whole database (visibility-scoped per user role).
     *                       Response: { data: [ { key, entities: [...] }, ... ] }
     *
     * With entity_id     → per-entity scan: returns flat list of candidates
     *                       for the given record (legacy / UI detail view).
     *                       Response: { data: [ DedupCandidate, ... ] }
     */
    public function scan(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $request->validate([
            'scope' => ['required', 'string', 'in:contact,company'],
            'entity_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $scope = $request->query('scope');
        $entityIdRaw = $request->query('entity_id');

        // ---- Global scan (no entity_id) ----
        if ($entityIdRaw === null) {
            $groups = $this->service->scanAll($scope, $request->user());

            $data = $groups->map(function (array $group): array {
                return [
                    'key' => $group['key'],
                    'entities' => DedupCandidateResource::collection($group['entities'])->resolve(),
                ];
            })->values();

            return response()->json(['data' => $data]);
        }

        // ---- Per-entity scan (entity_id present) ----
        $entityId = (int) $entityIdRaw;

        $entity = $this->resolveEntity($scope, $entityId);

        $this->authorize('view', $entity);

        $candidates = $this->service->scan($scope, $entityId);

        return DedupCandidateResource::collection($candidates);
    }

    /**
     * POST /crm/dedup/merge
     * Merges duplicate_ids into master_id.
     * Authorizes update on master AND on each duplicate.
     */
    public function merge(MergeDedupRequest $request): JsonResponse
    {
        $data = $request->validated();

        $master = $this->resolveEntity($data['scope'], $data['master_id']);
        $this->authorize('update', $master);

        foreach ($data['duplicate_ids'] as $dupId) {
            $dup = $this->resolveEntity($data['scope'], $dupId);
            $this->authorize('update', $dup);
        }

        $this->service->merge(
            $data['scope'],
            $data['master_id'],
            $data['duplicate_ids'],
            $request->user(),
        );

        return response()->json(['message' => 'Merge completed.']);
    }

    /**
     * POST /crm/dedup/dismiss
     * Marks a pair as "not duplicates".
     * Authorizes view on both entities.
     */
    public function dismiss(DismissDedupRequest $request): JsonResponse
    {
        $data = $request->validated();

        $entityA = $this->resolveEntity($data['scope'], $data['entity_a_id']);
        $this->authorize('view', $entityA);

        $entityB = $this->resolveEntity($data['scope'], $data['entity_b_id']);
        $this->authorize('view', $entityB);

        $this->service->dismiss(
            $data['scope'],
            $data['entity_a_id'],
            $data['entity_b_id'],
            $request->user(),
        );

        return response()->json(['message' => 'Pair dismissed.']);
    }

    /**
     * Resolve the entity model by scope and ID.
     * Throws ModelNotFoundException (404) for unknown IDs.
     */
    private function resolveEntity(string $scope, int $id): Model
    {
        return match ($scope) {
            'contact' => Contact::findOrFail($id),
            'company' => Company::findOrFail($id),
        };
    }
}
