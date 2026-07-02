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
     * Normalize the string boolean a query string always yields.
     *
     * Laravel 13.15's `boolean` rule only accepts native booleans or `1`/`0` — it
     * rejects the string `"true"`/`"false"` a GET query param carries (there are no
     * native bools in a URL). We coerce the accepted string forms to a real bool
     * BEFORE validation so the `boolean` rule passes, while leaving an absent param
     * as null (the "no filter" case the controller checks via
     * validated('is_active') !== null). An unrecognised value is left untouched so
     * the `boolean` rule still rejects it with a 422.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            return;
        }

        $normalized = filter_var(
            $this->input('is_active'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        // Only overwrite when the value is a recognised boolean form; otherwise
        // leave it so the `boolean` rule produces the validation error.
        if ($normalized !== null) {
            $this->merge(['is_active' => $normalized]);
        }
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
            // The admin user list also backs the Settings directory dropdowns
            // (manager / department-head pickers), which request the whole roster
            // in one page (per_page=200+). The endpoint is admin-gated, so a large
            // cap is safe; 500 comfortably covers the full company directory while
            // still bounding an unbounded request. A purpose-built thin endpoint
            // (GET /api/users → UserOptionResource) exists for non-admin dropdowns.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
