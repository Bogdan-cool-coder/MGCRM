<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a self-service profile update (Iam context).
 *
 * Fields the user may change about their own account:
 *  - full_name — display name;
 *  - locale — account-level UI language (persisted so a new device picks it up);
 *  - nav_quick_actions — personalised quick-action navigation (ordered list of
 *    up to 5 distinct action keys; `nullable` clears it back to the default).
 *
 * Sensitive fields (role, email, is_active, telegram, password) are NOT editable
 * here — they go through admin user-management / dedicated flows.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may edit their own profile; the route is
        // gated by auth:sanctum + 2fa and always targets $request->user().
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'locale' => ['sometimes', 'required', 'string', Rule::in(['ru', 'en'])],
            'nav_quick_actions' => ['nullable', 'array', 'max:5'],
            'nav_quick_actions.*' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }
}
