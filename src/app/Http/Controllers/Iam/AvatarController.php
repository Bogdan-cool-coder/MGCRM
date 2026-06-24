<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\AvatarService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\UpdateAvatarRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\UploadedFile;

/**
 * Self-service avatar management (Iam context).
 *
 * Thin controller (ARCHITECTURE.md §1): hand the uploaded file to AvatarService
 * and return the user via UserResource — the same shape as GET /api/me, so the
 * SPA refreshes its current-user store from the response.
 */
class AvatarController extends Controller
{
    public function __construct(
        private readonly AvatarService $avatars,
    ) {}

    /**
     * POST /api/profile/avatar — upload/replace the authenticated user's avatar.
     */
    public function store(UpdateAvatarRequest $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        $file = $request->file('avatar');
        \assert($file instanceof UploadedFile);

        $user = $this->avatars->store($user, $file);

        return UserResource::make($user);
    }

    /**
     * DELETE /api/profile/avatar — remove the authenticated user's avatar.
     */
    public function destroy(): JsonResource
    {
        /** @var User $user */
        $user = request()->user();

        $user = $this->avatars->remove($user);

        return UserResource::make($user);
    }
}
