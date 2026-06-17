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
            'job_title' => $this->job_title,  // S1.8: plan §М Q3
            'role' => $this->role?->value,
            'telegram_user_id' => $this->telegram_user_id,
            'avatar_path' => $this->avatar_path,
            'department_id' => $this->department_id,
            'manager_id' => $this->manager_id,
            'is_active' => $this->is_active,
            'locale' => $this->locale,
            'salary_currency' => $this->salary_currency,
            'nav_quick_actions' => $this->nav_quick_actions ?? [],
            'totp_enabled' => $this->totp_enabled,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
