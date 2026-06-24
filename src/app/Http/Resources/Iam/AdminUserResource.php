<?php

declare(strict_types=1);

namespace App\Http\Resources\Iam;

use App\Domain\Iam\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User row for the Settings user-management screen (admin).
 *
 * Exposes the directory fields the Settings list/form need: ФИО, email, phone,
 * job_title (должность), department (id + name), manager_id, role, is_active.
 * Never exposes secrets (totp_secret / backup_codes / password are $hidden on
 * the model and not referenced here).
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'manager_id' => $this->manager_id,
            'role' => $this->role?->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
