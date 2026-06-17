<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * DashboardRequest — validates the sales dashboard query parameters (S1.7).
 *
 * Authorization: any authenticated user may view their own scope.
 * manager_id filter: only admin/director may specify a foreign manager_id;
 * a manager submitting someone else's id receives a 422 (not a 403, so the
 * filter-url doesn't leak role information in status codes).
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Deal::class);
    }

    public function rules(): array
    {
        return [
            'period' => [
                'nullable',
                'string',
                Rule::in(['current_month', 'last_month', 'current_quarter', 'current_year']),
            ],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * Extra validation: only admin/director may filter by another user's manager_id.
     * A manager who submits a foreign manager_id gets a 422 — same as a rule violation.
     */
    protected function passedValidation(): void
    {
        $managerId = $this->filled('manager_id') ? (int) $this->input('manager_id') : null;

        if ($managerId === null) {
            return;
        }

        $user = $this->user();
        $roleName = $user->getRoleNames()->first() ?? $user->role?->value;

        $canFilterByOther = in_array($roleName, [Role::Admin->value, Role::Director->value], strict: true);

        if (! $canFilterByOther && $managerId !== $user->id) {
            throw ValidationException::withMessages([
                'manager_id' => 'Only admin and director may filter by a foreign manager_id.',
            ]);
        }
    }
}
