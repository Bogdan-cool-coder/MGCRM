<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\PasswordService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Self-service password change (Iam context).
 *
 * Thin controller (ARCHITECTURE.md §1): the FormRequest already proved ownership
 * via the current_password rule, so the action just hands the new password to
 * PasswordService and returns 200. No body is needed — the SPA keeps its token.
 */
class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordService $passwords,
    ) {}

    /**
     * POST /api/me/password — change the authenticated user's own password.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->passwords->change($user, $request->validated('password'));

        return response()->json(['message' => __('admin.password.changed')]);
    }
}
