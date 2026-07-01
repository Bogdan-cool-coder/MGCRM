<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Pipeline::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            // S1.5 — sales only (lifecycle/renewal are cs-specialist, S2).
            'kind' => ['nullable', 'in:sales'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
            // Pipeline-level visibility (M1, enforced in PipelineService::canAccess).
            // visible_role restricts the funnel to a single role; visible_user_ids to
            // an explicit set. Null/empty = visible to everyone (the default).
            'visible_role' => ['nullable', 'string', Rule::in(Role::values())],
            'visible_user_ids' => ['nullable', 'array'],
            'visible_user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
