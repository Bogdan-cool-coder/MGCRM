<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-param validation for GET /crm/custom-fields/schema.
 *
 * Replaces the inline $request->validate([...]) in CustomFieldDefController::schema()
 * per the black-list rule in docs/backend-standard.md §1.
 */
class SchemaCustomFieldDefsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_scope' => ['required', 'string', Rule::enum(CustomFieldScope::class)],
        ];
    }
}
