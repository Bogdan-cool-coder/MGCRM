<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\HoldingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AttachHoldingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * HoldingController — company group hierarchy endpoints.
 * Routes: GET/POST/DELETE /companies/{company}/holding
 *
 * Always returns the tree (empty if no group). Backend always sends data;
 * frontend HoldingTree.vue renders the empty-state when children=[].
 */
class HoldingController extends Controller
{
    public function __construct(
        private readonly HoldingService $service,
    ) {}

    /**
     * GET /companies/{company}/holding
     * Returns tree + ancestors. Empty children if no subsidiaries.
     */
    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $tree = $this->service->buildTree($company);

        return response()->json(['data' => $tree]);
    }

    /**
     * POST /companies/{company}/holding
     * Attach $company to a parent (holding group).
     */
    public function attach(AttachHoldingRequest $request, Company $company): JsonResponse
    {
        try {
            $role = $request->validated('holding_role') !== null
                ? HoldingRole::from($request->validated('holding_role'))
                : HoldingRole::Subsidiary;

            $this->service->setParent(
                $company,
                (int) $request->validated('parent_id'),
                $role,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'holding_cycle', 'message' => $e->getMessage()], 422);
        }

        $tree = $this->service->buildTree($company->fresh());

        return response()->json(['data' => $tree]);
    }

    /**
     * DELETE /companies/{company}/holding
     * Detach $company from its holding group.
     */
    public function detach(Request $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $this->service->detach($company);

        $tree = $this->service->buildTree($company->fresh());

        return response()->json(['data' => $tree]);
    }
}
