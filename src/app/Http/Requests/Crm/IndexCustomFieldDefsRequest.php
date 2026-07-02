<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-param validation for GET /crm/custom-fields (admin list).
 *
 * Replaces the inline $request->validate([...]) in CustomFieldDefController::index()
 * per the black-list rule in docs/backend-standard.md §1.
 */
class IndexCustomFieldDefsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope'            => ['nullable', 'string', Rule::enum(CustomFieldScope::class)],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
