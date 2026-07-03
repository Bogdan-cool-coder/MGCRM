<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales\Motivation;

use App\Domain\Sales\Services\ManagerKpiService;
use App\Domain\Sales\Services\MotivationCardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\MotivationCabinetRequest;
use App\Http\Resources\Sales\MotivationCardResource;

/**
 * Thin controller for the Motivation Card (МК) manager cabinet (contract §6.1).
 * All business logic delegated to MotivationCardService.
 */
class MotivationCardController extends Controller
{
    public function __construct(
        private readonly MotivationCardService $motivationCardService,
        private readonly ManagerKpiService $kpiService,
    ) {}

    /**
     * GET /api/motivation/cards/me
     */
    public function me(MotivationCabinetRequest $request): MotivationCardResource
    {
        $viewer = $request->user();
        $requestedUserId = $request->filled('user_id') ? (int) $request->input('user_id') : null;

        // resolveTargetUser() is the SAME visibility gate as the S1.8 cabinet
        // (contract §3.5) so read scope never diverges: manager → own only
        // (throws 403 on any other user_id); director → own + subordinates
        // (manager-cabinet.view-all); admin → any. Throws HttpResponseException
        // itself — no separate Policy call needed here.
        $target = $this->kpiService->resolveTargetUser($viewer, $requestedUserId);

        $payload = $this->motivationCardService->buildCabinetPayload(
            $target->id,
            (int) $request->input('year'),
            (int) $request->input('month'),
            $viewer,
        );

        return new MotivationCardResource($payload);
    }
}
