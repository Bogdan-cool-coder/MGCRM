<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Iam\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'role' => $this->role?->value,
            'telegram_user_id' => $this->telegram_user_id,
            'avatar_path' => $this->avatar_path,
            'department_id' => $this->department_id,
            'manager_id' => $this->manager_id,
            'is_active' => $this->is_active,
            'locale' => $this->locale,
            'totp_enabled' => $this->totp_enabled,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
