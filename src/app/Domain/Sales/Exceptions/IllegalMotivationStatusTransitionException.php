<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use App\Domain\Sales\Enums\MotivationCardStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by MotivationCardService::assertCanTransition() on an illegal edge.
 * Allowed edges (Phase A, contract §9 Q7): draft→finalized, finalized→paid.
 * Un-finalize (finalized→draft) is denied by default.
 */
final class IllegalMotivationStatusTransitionException extends RuntimeException
{
    public const ERROR_CODE = 'illegal_motivation_status_transition';

    public function __construct(MotivationCardStatus $from, MotivationCardStatus $to)
    {
        parent::__construct("Cannot transition a motivation card from \"{$from->value}\" to \"{$to->value}\".");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => self::ERROR_CODE,
        ], 409);
    }
}
