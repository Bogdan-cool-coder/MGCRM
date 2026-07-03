<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales\Motivation;

use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\TeamKpiRule;
use App\Domain\Sales\Services\MotivationCardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CopyPreviousMotivationPlanRequest;
use App\Http\Requests\Sales\FinalizeMotivationCardRequest;
use App\Http\Requests\Sales\MarkPaidMotivationCardRequest;
use App\Http\Requests\Sales\ShowMotivationPlanRequest;
use App\Http\Requests\Sales\StoreMotivationPlanRequest;
use App\Http\Resources\Sales\MotivationPlanResource;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller for the Motivation Card (МК) constructor (contract §6.2/6.3).
 * All routes are gated by `can:motivation.manage` middleware (route group) or
 * `can:motivation.status` (status-transition FormRequests). Business logic
 * delegated to MotivationCardService.
 */
class MotivationPlanController extends Controller
{
    public function __construct(
        private readonly MotivationCardService $motivationCardService,
    ) {}

    /**
     * GET /api/admin/motivation/plans/{userId}/{year}/{month}
     */
    public function show(ShowMotivationPlanRequest $request, int $userId, int $year, int $month): JsonResponse
    {
        $card = $this->motivationCardService->findForConstructor($userId, $year, $month);

        if ($card === null) {
            return response()->json(['data' => null]);
        }

        $teamRule = $this->teamRuleFor($card);

        return response()->json(['data' => (new MotivationPlanResource($card, $teamRule))->toArray($request)]);
    }

    /**
     * POST /api/admin/motivation/plans
     */
    public function store(StoreMotivationPlanRequest $request): JsonResponse
    {
        $existing = MotivationCard::query()
            ->where('user_id', $request->integer('user_id'))
            ->where('period_year', $request->integer('year'))
            ->where('period_month', $request->integer('month'))
            ->exists();

        $card = $this->motivationCardService->upsertPlan($request->validated(), $request->user());
        $teamRule = $this->teamRuleFor($card);

        return (new MotivationPlanResource($card, $teamRule))
            ->response()
            ->setStatusCode($existing ? 200 : 201);
    }

    /**
     * POST /api/admin/motivation/plans/copy-previous
     */
    public function copyPrevious(CopyPreviousMotivationPlanRequest $request): JsonResponse
    {
        $card = $this->motivationCardService->copyFromPreviousMonth(
            $request->integer('user_id'),
            $request->integer('year'),
            $request->integer('month'),
        );

        if ($card === null) {
            return response()->json(['data' => null]);
        }

        $teamRule = $this->teamRuleFor($card);

        return response()->json(['data' => (new MotivationPlanResource($card, $teamRule))->toArray($request)]);
    }

    /**
     * POST /api/admin/motivation/plans/{card}/finalize
     */
    public function finalize(FinalizeMotivationCardRequest $request, MotivationCard $card): MotivationPlanResource
    {
        $card = $this->motivationCardService->finalize($card, $request->user());
        $card->load('items');

        return new MotivationPlanResource($card, $this->teamRuleFor($card));
    }

    /**
     * POST /api/admin/motivation/plans/{card}/mark-paid
     */
    public function markPaid(MarkPaidMotivationCardRequest $request, MotivationCard $card): MotivationPlanResource
    {
        $card = $this->motivationCardService->markPaid($card, $request->user());
        $card->load('items');

        return new MotivationPlanResource($card, $this->teamRuleFor($card));
    }

    private function teamRuleFor(MotivationCard $card): ?TeamKpiRule
    {
        if ($card->pipeline_id === null) {
            return null;
        }

        return TeamKpiRule::query()
            ->where('pipeline_id', $card->pipeline_id)
            ->where('period_year', $card->period_year)
            ->where('period_month', $card->period_month)
            ->first();
    }
}
