<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Domain\Iam\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query params for the colleague directory (GET /api/users).
 *
 * It is a read-only reference list of co-workers used by assign/responsible
 * dropdowns, so any authenticated user may call it (authorization happens in
 * the route middleware). ?search= filters by name/email; ?role= filters by
 * spatie role name (Motivation Cards manager AutoComplete, contract §7.2);
 * ?managed_by= scopes to a leader's subordinates ("me" = the caller).
 */
class UserIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(Role::values())],
            'managed_by' => ['nullable', 'string', 'max:32'],
        ];
    }
}
