<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationRunQueryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\IndexAutomationRunRequest;
use App\Http\Resources\Automation\AutomationRunResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AutomationRunController (M7 P4) — read-only runs journal.
 *
 * Thin pass-through: authorize → AutomationRunQueryService (composes the filtered,
 * newest-first, paginated query) → AutomationRunResource. Gated the same as the
 * builder (viewAny on PipelineAutomation). No writes — the engine owns run
 * lifecycle; this is the audit view the admin reads and the analytics-specialist
 * aggregates over.
 */
class AutomationRunController extends Controller
{
    public function __construct(
        private readonly AutomationRunQueryService $queries,
    ) {}

    public function index(IndexAutomationRunRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PipelineAutomation::class);

        $runs = $this->queries->paginate($request->toFilter(), $request->perPage());

        return AutomationRunResource::collection($runs);
    }
}
