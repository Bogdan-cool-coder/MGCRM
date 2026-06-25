<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/departments/{department}/members — bulk-assign users
 * to a department. user_ids must reference existing users.
 */
class AddDepartmentMembersRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
