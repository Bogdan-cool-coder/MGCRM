<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by DealMoveService (S2.8) when a deal is moved into a won stage whose
 * contract gate is on but no "live" contract (approved/signed/uploaded) exists.
 *
 * Renders 409 Conflict with a stable error_code so the frontend (S2.10) can show
 * a localized toast by code rather than parsing the human message.
 */
final class WonGateException extends RuntimeException
{
    public const ERROR_CODE = 'won_gate_contract_required';

    public function __construct()
    {
        parent::__construct('Cannot move the deal to a won stage without an active contract.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => self::ERROR_CODE,
        ], 409);
    }
}
