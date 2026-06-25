<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/admin/roles/{role}/permissions — replace a role's spatie
 * permission set. The role name is the route param; the body is the full set of
 * permission names to grant. Unknown-name / admin-not-lockable enforcement lives
 * in RolePermissionMatrixService (422).
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
