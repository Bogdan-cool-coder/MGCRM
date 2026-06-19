<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\DealResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CompanyDealsController — list deals belonging to a specific company.
 * Cross-domain: Crm company → Sales deals (ARCHITECTURE.md §2 — via public Service method).
 *
 * Route: GET /api/companies/{company}/deals
 */
class CompanyDealsController extends Controller
{
    public function __construct(
        private readonly DealService $service,
    ) {}

    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $deals = $this->service->listForCompany($company, $request->query());

        return DealResource::collection($deals);
    }
}
