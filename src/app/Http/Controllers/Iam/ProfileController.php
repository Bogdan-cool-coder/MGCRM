<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\ProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Self-service profile editing (Iam context).
 *
 * Thin controller (ARCHITECTURE.md §1): pull the validated payload, call one
 * ProfileService method, return the user via UserResource — the same shape as
 * GET /api/me, so the SPA can refresh its current-user store from the response.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profile,
    ) {}

    /**
     * PATCH /api/me/profile — update the authenticated user's own profile.
     */
    public function update(UpdateProfileRequest $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->profile->update($user, $request->validated());

        return UserResource::make($user);
    }
}
