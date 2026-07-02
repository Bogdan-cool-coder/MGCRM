<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk reorder request for CustomFieldDefs within a single entity_scope.
 *
 * entity_scope comes from the query string (PATCH /crm/custom-fields/reorder?entity_scope=).
 * items[] describes the new sort_order for each def id.
 */
class ReorderCustomFieldDefsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate admin-write checked in controller via $this->authorize().
    }

    public function rules(): array
    {
        return [
            'entity_scope' => ['required', 'string', Rule::enum(CustomFieldScope::class)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:custom_field_defs,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.id.exists' => 'One or more custom field ids do not exist.',
        ];
    }

    /**
     * Allow entity_scope from the query string in addition to the request body.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge($this->query(), $this->all());
    }
}
