<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyChannel;
use App\Domain\Crm\Services\CompanyChannelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCompanyChannelRequest;
use App\Http\Requests\Crm\UpdateCompanyChannelRequest;
use App\Http\Resources\Crm\CompanyChannelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin controller for company channels (ARCHITECTURE.md §1).
 * Routes: GET/POST /companies/{company}/channels
 *         PATCH/DELETE /companies/{company}/channels/{channel}
 */
class CompanyChannelController extends Controller
{
    public function __construct(
        private readonly CompanyChannelService $service,
    ) {}

    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $channels = $this->service->list($company);

        return CompanyChannelResource::collection($channels);
    }

    public function store(StoreCompanyChannelRequest $request, Company $company): JsonResource
    {
        $channel = $this->service->create($company, $request->validated());

        return CompanyChannelResource::make($channel);
    }

    public function update(UpdateCompanyChannelRequest $request, Company $company, CompanyChannel $channel): JsonResource
    {
        // Ensure the channel belongs to the route-bound company (prevent IDOR).
        abort_if((int) $channel->company_id !== (int) $company->id, 404);

        $updated = $this->service->update($channel, $request->validated());

        return CompanyChannelResource::make($updated);
    }

    public function destroy(Request $request, Company $company, CompanyChannel $channel): JsonResponse
    {
        $this->authorize('update', $company);

        // Ensure the channel belongs to the route-bound company (prevent IDOR).
        abort_if((int) $channel->company_id !== (int) $company->id, 404);

        $this->service->delete($channel);

        return response()->json(['message' => 'Channel deleted.']);
    }
}
