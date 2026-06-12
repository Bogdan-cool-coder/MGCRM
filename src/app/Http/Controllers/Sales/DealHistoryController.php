<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\DealStageHistoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin read-only controller for a deal's stage-transition log.
 */
class DealHistoryController extends Controller
{
    public function index(Request $request, Deal $deal): AnonymousResourceCollection
    {
        $this->authorize('view', $deal);

        $history = DealStageHistory::query()
            ->where('deal_id', $deal->id)
            ->with(['fromStage:id,name', 'toStage:id,name', 'user:id,full_name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return DealStageHistoryResource::collection($history);
    }
}
