<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the create-user payload (POST /api/admin/users).
 *
 * Fields: ФИО (full_name), почта (email), телефон (phone), должность
 * (job_title), отдел (department_id), руководитель (manager_id). role is
 * optional and defaults to manager in UserService. password is optional —
 * UserService generates one when absent.
 *
 * The admin gate is enforced in the controller via $this->authorize(); this
 * request only validates the body.
 */
class StoreUserRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:64'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', Rule::in(Role::values())],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
