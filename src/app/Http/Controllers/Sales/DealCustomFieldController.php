<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Sales\Models\Deal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only controller exposing a deal's custom-field definitions (scope=deal)
 * enriched with the deal's current values. Backs the "Основное" tab in DealPage.
 */
class DealCustomFieldController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $customFieldService,
    ) {}

    public function index(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('view', $deal);

        return response()->json([
            'data' => $this->customFieldService->readFields($deal),
        ]);
    }
}
