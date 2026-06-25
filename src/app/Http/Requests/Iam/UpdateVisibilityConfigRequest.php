<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/visibility-config — update the visibility scope for
 * one or more roles. Body is a flat { role: scope } map, e.g.
 * { "manager": "department", "accountant": "own" }. Keys must be known roles,
 * values must be a VisibilityScope value (all | department | own).
 */
class UpdateVisibilityConfigRequest extends FormRequest
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
        $rules = ['config' => ['required', 'array', 'min:1']];

        foreach (Role::values() as $role) {
            $rules["config.{$role}"] = ['sometimes', 'string', Rule::in(VisibilityScope::values())];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        // Accept either a flat { manager: 'own' } body or a wrapped
        // { config: { manager: 'own' } } body; normalize to the wrapped shape.
        if (! $this->has('config')) {
            $payload = array_intersect_key($this->all(), array_flip(Role::values()));
            $this->merge(['config' => $payload]);
        }
    }
}
