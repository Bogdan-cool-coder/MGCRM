<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate attaching a company to a holding group.
 */
class AttachHoldingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        if (! $this->user()->can('update', $company)) {
            return false;
        }

        // Also authorize the parent company being attached under.
        // Prevents grafting a company under a holding the user cannot see.
        $parentId = (int) $this->input('parent_id');
        if ($parentId > 0) {
            $parent = Company::find($parentId);
            if ($parent === null || ! $this->user()->can('view', $parent)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'parent_id' => [
                'required',
                'integer',
                'exists:crm_companies,id',
                Rule::notIn([$company->id]),
            ],
            'holding_role' => [
                'nullable',
                Rule::enum(HoldingRole::class),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'A company cannot be its own parent.',
        ];
    }
}
