<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validate an admin password (re)set (POST /api/admin/users/{user}/reset-password).
 *
 * Body is OPTIONAL:
 *  - password absent  → PasswordService generates a strong random password;
 *  - password present → the admin-supplied value is used, enforced to min 8 so a
 *    weak manual override cannot slip in.
 *
 * The admin gate is enforced in the controller via $this->authorize('admin-write')
 * (and the can:admin-write route group). This request only validates the body.
 */
class ResetUserPasswordRequest extends FormRequest
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
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }
}
