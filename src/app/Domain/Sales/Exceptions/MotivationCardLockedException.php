<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by MotivationCardService::upsertPlan() when a write targets a
 * finalized/paid card (contract §6.3 — "409 if the card is finalized/paid").
 * Phase A has no hard fact-freeze, but write endpoints are blocked once
 * finalized (contract §9 Q8).
 */
final class MotivationCardLockedException extends RuntimeException
{
    public const ERROR_CODE = 'motivation_card_locked';

    public function __construct()
    {
        parent::__construct('This motivation card is finalized/paid and can no longer be edited.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => self::ERROR_CODE,
        ], 409);
    }
}
