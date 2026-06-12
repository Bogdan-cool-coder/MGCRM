<?php

declare(strict_types=1);

namespace App\Http\Resources\Iam;

use App\Domain\Iam\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight user option for assign / responsible dropdowns.
 *
 * Deliberately exposes ONLY safe directory fields — never password,
 * totp_secret or backup_codes (those are $hidden on the model anyway, but we
 * also never reference them here). Shape matches front/src/api/users.ts
 * (UserOptionDto): id, full_name, email, avatar_path, plus department_id/role
 * for richer AutoComplete rendering on the front.
 *
 * @mixin User
 */
class UserOptionResource extends JsonResource
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
            'avatar_path' => $this->avatar_path,
            'department_id' => $this->department_id,
            'role' => $this->role?->value,
        ];
    }
}
