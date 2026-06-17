<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Services\PriceImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ImportPriceRequest;
use App\Http\Resources\Catalog\ImportResultResource;
use Illuminate\Http\JsonResponse;

class PriceImportController extends Controller
{
    public function __construct(
        private readonly PriceImportService $service,
    ) {}

    /**
     * POST /api/catalog/price-import
     * Real import: writes to DB. Returns 200 or 422 (when errors exist).
     */
    public function store(ImportPriceRequest $request): JsonResponse
    {
        $result = $this->service->importFromExcel(
            $request->file('file'),
            dryRun: false,
        );

        $statusCode = count($result->errors) > 0 ? 422 : 200;

        return ImportResultResource::make($result)->response()->setStatusCode($statusCode);
    }

    /**
     * POST /api/catalog/price-import/preview
     * Dry-run: parses the file, returns what would be inserted/updated. Does NOT write.
     */
    public function preview(ImportPriceRequest $request): JsonResponse
    {
        $result = $this->service->importFromExcel(
            $request->file('file'),
            dryRun: true,
        );

        return ImportResultResource::make($result)->response()->setStatusCode(200);
    }
}
