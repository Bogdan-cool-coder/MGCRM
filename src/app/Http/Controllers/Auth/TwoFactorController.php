<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\AuthService;
use App\Domain\Iam\Services\LoginThrottle;
use App\Domain\Iam\Services\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ValidateTwoFactorRequest;
use App\Http\Requests\Auth\VerifyTwoFactorSetupRequest;
use App\Http\Resources\TwoFactorSetupResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/**
 * Thin 2FA controller (ARCHITECTURE.md §1). All logic lives in TwoFactorService
 * / AuthService. Setup + verify-setup run on a full token; validate runs on the
 * limited temp token issued at login.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuthService $auth,
        private readonly LoginThrottle $throttle,
    ) {}

    /**
     * POST /api/2fa/setup — generate a candidate secret + provisioning URI.
     * Nothing is persisted until /2fa/verify-setup confirms a valid code.
     */
    public function setup(Request $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->totp_enabled) {
            throw ValidationException::withMessages([
                'totp' => [__('auth.two_factor_already_enabled')],
            ]);
        }

        $secret = $this->twoFactor->generateSecret();

        return TwoFactorSetupResource::make([
            'secret' => $secret,
            'otpauth_uri' => $this->twoFactor->provisioningUri($user->email, $secret),
        ]);
    }

    /**
     * POST /api/2fa/verify-setup — verify the first code against the candidate
     * secret, then persist (enable 2FA) and return one-time backup codes.
     */
    public function verifySetup(VerifyTwoFactorSetupRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->totp_enabled) {
            throw ValidationException::withMessages([
                'totp' => [__('auth.two_factor_already_enabled')],
            ]);
        }

        $secret = $request->validated('secret');

        if (! $this->twoFactor->verifyCode($secret, $request->validated('totp_code'))) {
            throw ValidationException::withMessages([
                'totp_code' => [__('auth.two_factor_invalid')],
            ]);
        }

        $backupCodes = $this->twoFactor->enable($user, $secret);

        return response()->json([
            'two_factor_enabled' => true,
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * POST /api/2fa/validate — finalize login: validate the TOTP/backup code on
     * the temp token and upgrade to a full token.
     */
    public function validateCode(ValidateTwoFactorRequest $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->totp_enabled) {
            throw ValidationException::withMessages([
                'totp' => [__('auth.two_factor_disabled')],
            ]);
        }

        // Brute-force gate (IAM-2): reject BEFORE verifying TOTP once the cap of
        // consecutive FAILED second-factor attempts is hit. Keyed by the
        // temp-token user id + IP. Failures-only — a valid code clears the budget.
        $this->throttle->ensureTwoFactorNotLocked($request);

        try {
            $token = $this->auth->completeTwoFactor(
                $user,
                $request->validated('totp_code'),
                $request->validated('backup_code'),
            );
        } catch (ValidationException $e) {
            // Wrong TOTP / backup code counts as a failure.
            $this->throttle->hitTwoFactor($request);

            throw $e;
        }

        // Success resets the 2FA budget.
        $this->throttle->clearTwoFactor($request);

        return UserResource::make($user)->additional([
            'token' => $token->plainTextToken,
        ]);
    }
}
