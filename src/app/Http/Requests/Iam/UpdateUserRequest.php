<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin edit-user payload (PATCH /api/admin/users/{user}).
 *
 * Partial update: every field is `sometimes`, so the controller/service only
 * touch keys that are actually present. email uniqueness ignores the row being
 * edited; password is optional (empty/absent leaves the credential untouched).
 *
 * The admin gate is enforced in the controller via $this->authorize(); this
 * request only validates the body.
 */
class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'manager_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                // A user cannot be their own line manager.
                Rule::notIn($userId !== null ? [$userId] : []),
            ],
            'role' => ['sometimes', 'required', 'string', Rule::in(Role::values())],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
