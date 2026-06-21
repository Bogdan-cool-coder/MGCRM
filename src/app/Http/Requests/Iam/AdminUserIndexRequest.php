<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query params for the admin user-management list (GET /api/admin/users).
 *
 * The admin gate is enforced in the controller via $this->authorize(); this
 * request only validates the optional search / filter params.
 */
class AdminUserIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(Role::values())],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
