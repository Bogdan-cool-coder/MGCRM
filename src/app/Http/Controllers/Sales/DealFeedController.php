<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealFeedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin read-only controller for a deal's unified event feed (stage changes,
 * activities and field changes merged chronologically). The merge/normalise/
 * paginate logic lives entirely in DealFeedService.
 */
class DealFeedController extends Controller
{
    public function __construct(
        private readonly DealFeedService $feed,
    ) {}

    public function index(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('view', $deal);

        $types = $request->query('types');

        $result = $this->feed->feed(
            $deal,
            ['types' => is_array($types) ? $types : []],
            (int) $request->query('page', '1'),
            $this->perPage($request),
        );

        return response()->json($result);
    }

    /**
     * Clamp per_page to a sane window (1..100), mirroring EntityLogController.
     * The merge loads bounded sources, so an unbounded per_page can no longer
     * blow up the response.
     */
    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', '30');

        return max(1, min($perPage, 100));
    }
}
