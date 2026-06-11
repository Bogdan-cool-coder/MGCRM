<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin auth controller (ARCHITECTURE.md §1): parse FormRequest, call one
 * AuthService method, return a Resource. Sanctum Bearer tokens; 2FA users get a
 * limited temp token here and complete login at /api/2fa/validate.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    /**
     * POST /api/login
     *
     * 2FA off  -> { user, token } (full token).
     * 2FA on   -> { user, two_factor_required: true, temp_token } (limited token;
     *             the SPA then calls /2fa/validate to upgrade it).
     */
    public function login(LoginRequest $request): JsonResource
    {
        $user = $this->auth->authenticate(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($this->auth->requiresTwoFactor($user)) {
            $temp = $this->auth->issueTempToken($user);

            return UserResource::make($user)->additional([
                'two_factor_required' => true,
                'temp_token' => $temp->plainTextToken,
            ]);
        }

        $token = $this->auth->issueApiToken($user);

        return UserResource::make($user)->additional([
            'two_factor_required' => false,
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * POST /api/logout — revoke the token that authenticated this request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => __('auth.logged_out')]);
    }

    /**
     * GET /api/me — current authenticated user.
     */
    public function me(Request $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user);
    }
}
