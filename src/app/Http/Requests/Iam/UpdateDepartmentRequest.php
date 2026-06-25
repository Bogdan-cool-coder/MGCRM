<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/departments/{department} (rename / re-parent /
 * change head). Partial update: every field is `sometimes`. The cycle guard
 * (parent must not be self or a descendant) lives in DepartmentService — this
 * request only validates shape + a cheap self-parent check.
 */
class UpdateDepartmentRequest extends FormRequest
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
        $id = $this->route('department')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:departments,id',
                Rule::notIn($id !== null ? [$id] : []),
            ],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
